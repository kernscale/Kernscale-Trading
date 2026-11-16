import asyncio
import socket
import unittest
from unittest.mock import AsyncMock, patch

from bridge.network import GO_BASE_URL, public_target


class NetworkTest(unittest.IsolatedAsyncioTestCase):
    async def test_private_mapped_metadata_and_credentials_denied(self):
        for url in ('http://127.0.0.1', 'https://10.1.2.3', 'http://169.254.169.254',
                    'http://[::1]', 'http://[::ffff:127.0.0.1]', 'http://user:secret@example.com',
                    'file:///etc/passwd', 'http://8.8.8.8:8080'):
            with self.subTest(url=url), self.assertRaises(ValueError):
                await public_target(url)

    async def test_dns_checked_once_and_connection_uses_literal_with_original_tls_host(self):
        loop = asyncio.get_running_loop()
        records = [(socket.AF_INET, socket.SOCK_STREAM, 6, '', ('93.184.216.34', 443))]
        with patch.object(loop, 'getaddrinfo', AsyncMock(return_value=records)) as lookup:
            target, authority, sni = await public_target('https://example.com/path')
        self.assertEqual(lookup.await_count, 1)
        self.assertEqual(target.host, '93.184.216.34')
        self.assertEqual(authority, 'example.com')
        self.assertEqual(sni, 'example.com')

    async def test_mixed_public_private_dns_denied(self):
        loop = asyncio.get_running_loop()
        records = [(socket.AF_INET, socket.SOCK_STREAM, 6, '', (ip, 443)) for ip in ('93.184.216.34', '10.0.0.1')]
        with patch.object(loop, 'getaddrinfo', AsyncMock(return_value=records)), self.assertRaises(ValueError):
            await public_target('https://example.com')

    def test_approved_go_route(self):
        self.assertEqual(GO_BASE_URL, 'https://opencode.ai/inference/go/openai/v1')
