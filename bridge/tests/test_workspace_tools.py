import json
import unittest
from uuid import uuid4

from bridge.workspace_tools import build_workspace_tools


class WorkspaceToolsTest(unittest.TestCase):
    def setUp(self):
        self.id = str(uuid4())
        self.snapshot = {'thesis': {'content': 'Original', 'version': 3}, 'board': [
            {'id': self.id, 'title': 'Research', 'content': '', 'status': 'backlog', 'version': 2}]}
        self.events = []
        tools = build_workspace_tools(self.snapshot, lambda kind, payload: self.events.append((kind, payload)), lambda **kw: kw)
        self.tools = {tool['name']: tool['execute'] for tool in tools}

    def test_updates_require_snapshot_id_and_version(self):
        for proposal in (
            {'operation': 'update_card', 'card_id': str(uuid4()), 'content': 'Bad', 'expected_version': 2},
            {'operation': 'update_card', 'card_id': self.id, 'content': 'Bad', 'expected_version': 1},
            {'operation': 'update_thesis', 'content': 'Bad', 'expected_version': 1},
            {'operation': 'create_card', 'title': 'Bad', 'status': 'unsupported'},
            {'operation': 'update_thesis', 'content': 'Bad'},
            {'operation': 'shell', 'content': 'Bad'},
        ):
            with self.subTest(proposal=proposal), self.assertRaises(ValueError):
                self.tools['workspace_propose'](proposal, None)
        self.assertEqual(self.events, [])

    def test_valid_flat_proposal_preserves_snapshot(self):
        proposal = {'operation': 'update_card', 'card_id': self.id, 'content': 'Proposed', 'expected_version': 2}
        self.assertIn('awaiting human review', self.tools['workspace_propose'](proposal, None))
        self.assertEqual(self.events, [('workspace_proposal', proposal)])
        read = json.loads(self.tools['workspace_read']({}, None))
        self.assertEqual(read['board'][0]['content'], '')
        self.assertEqual(self.snapshot['thesis']['content'], 'Original')
