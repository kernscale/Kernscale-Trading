"""Immutable session snapshot and validated proposals; Laravel owns human approval."""
from typing import Literal
from uuid import UUID

from pydantic import BaseModel, ConfigDict, Field, model_validator


class StrictModel(BaseModel):
    model_config = ConfigDict(extra='forbid')


class Thesis(StrictModel):
    content: str = ''
    version: int = Field(default=1, ge=1)


class Card(StrictModel):
    id: UUID
    title: str
    content: str = ''
    status: Literal['backlog', 'researching', 'review', 'done']
    version: int = Field(ge=1)


class Workspace(StrictModel):
    thesis: Thesis = Field(default_factory=Thesis)
    board: list[Card] = Field(default_factory=list)

    @model_validator(mode='after')
    def unique_cards(self):
        if len({card.id for card in self.board}) != len(self.board):
            raise ValueError('workspace card IDs must be unique')
        return self


class Proposal(StrictModel):
    operation: Literal['create_card', 'update_card', 'update_thesis']
    card_id: UUID | None = None
    title: str | None = None
    content: str | None = None
    status: Literal['backlog', 'researching', 'review', 'done'] | None = None
    expected_version: int | None = Field(default=None, ge=1)

    @model_validator(mode='after')
    def operation_fields(self):
        if self.operation == 'create_card':
            if not self.title or self.expected_version is not None:
                raise ValueError('new cards need a title and no expected version')
        elif self.operation == 'update_card':
            if self.card_id is None or self.expected_version is None:
                raise ValueError('card updates need a card ID and expected version')
            if self.title is None and self.content is None and self.status is None:
                raise ValueError('card update needs a changed field')
        elif self.content is None or self.expected_version is None or any(value is not None for value in (self.card_id, self.title, self.status)):
            raise ValueError('thesis updates need only content and expected version')
        return self


def build_workspace_tools(snapshot, emit, host_tool):
    workspace = Workspace.model_validate(snapshot or {})
    immutable = workspace.model_dump(mode='json')

    def read(args, context):
        if args:
            raise ValueError('workspace_read takes no arguments')
        # Return a serialization, never a mutable alias of the request snapshot.
        import json
        return json.dumps(immutable)

    def propose(args, context):
        proposal = Proposal.model_validate(args)
        if proposal.operation == 'update_thesis':
            if proposal.expected_version != workspace.thesis.version:
                raise ValueError('thesis version does not match this run snapshot')
        elif proposal.operation == 'update_card':
            card = next((card for card in workspace.board if card.id == proposal.card_id), None)
            if card is None or card.version != proposal.expected_version:
                raise ValueError('card ID/version does not match this run snapshot')
        elif proposal.card_id and any(card.id == proposal.card_id for card in workspace.board):
            raise ValueError('new card ID already exists')
        emit('workspace_proposal', proposal.model_dump(mode='json', exclude_none=True))
        return 'Proposal recorded; awaiting human review. The workspace has not changed.'

    return [host_tool(name='workspace_read', description='Read the immutable thesis and board snapshot for this private research run.',
                      parameters={'type': 'object', 'properties': {}, 'additionalProperties': False}, execute=read, load_mode='essential'),
            host_tool(name='workspace_propose', description='Propose a thesis or board change for human review; never applies a change directly.',
                      parameters=Proposal.model_json_schema(), execute=propose, load_mode='essential')]
