"""Typed clarification contract. No defaults imply a human answer or permission."""
import hashlib
import json
from uuid import UUID

from pydantic import BaseModel, ConfigDict, Field, field_validator, model_validator

MAX_CONTROL_BYTES = 64 * 1024


def bounded_json(value):
    encoded = json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=False)
    if len(encoded.encode('utf-8')) > MAX_CONTROL_BYTES:
        raise ValueError('clarification control payload exceeds transport bound')
    return encoded


class Strict(BaseModel):
    model_config = ConfigDict(extra='forbid')


class Option(Strict):
    label: str = Field(min_length=1, max_length=512)
    description: str = Field(default='', max_length=2048)


class Question(Strict):
    id: str = Field(pattern=r'^[0-2]$')
    question: str = Field(min_length=1, max_length=4096)
    header: str = Field(min_length=1, max_length=16)
    options: list[Option] = Field(min_length=2, max_length=4)


class ClarificationRequest(Strict):
    request_id: str
    tool_call_id: str = Field(min_length=1, max_length=200)
    questions: list[Question] = Field(min_length=1, max_length=3)

    @model_validator(mode='after')
    def canonical(self):
        if str(UUID(self.request_id)) != self.request_id:
            raise ValueError('request ID must be a canonical UUID')
        if [question.id for question in self.questions] != [str(i) for i in range(len(self.questions))]:
            raise ValueError('question IDs must be canonical and ordered')
        bounded_json(self.model_dump())
        return self


class Answer(Strict):
    id: str = Field(pattern=r'^[0-2]$')
    answer: str = Field(min_length=1, max_length=8192)

    @field_validator('answer')
    @classmethod
    def nonblank(cls, value):
        if not value.strip():
            raise ValueError('answer must not be blank')
        return value


class Answers(Strict):
    answers: list[Answer] = Field(min_length=1, max_length=3)

    @model_validator(mode='after')
    def unique(self):
        if len({answer.id for answer in self.answers}) != len(self.answers):
            raise ValueError('answer IDs must be distinct')
        bounded_json(self.model_dump())
        return self


def canonical_answers(body, questions):
    answers = Answers.model_validate(body).answers
    if {answer.id for answer in answers} != {question['id'] for question in questions}:
        raise ValueError('exactly one answer per question is required')
    result = [answer.model_dump() for answer in sorted(answers, key=lambda answer: answer.id)]
    encoded = bounded_json({'answers': result})
    digest = hashlib.sha256(encoded.encode('utf-8')).hexdigest()
    bounded_json({'type': 'clarification_answer', 'request_id': '0' * 36, 'answers': result, 'answer_digest': digest})
    return result, digest
