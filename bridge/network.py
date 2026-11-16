"""Public web retrieval: pin each resolved IP through the request, including redirects."""
import asyncio
import ipaddress
import socket
from urllib.parse import urljoin, urlsplit

import httpx

GO_BASE_URL = 'https://opencode.ai/inference/go/openai/v1'
MAX_BODY = 10 * 1024 * 1024


async def public_target(url):
    parsed = urlsplit(url)
    if parsed.scheme not in ('http', 'https') or not parsed.hostname or parsed.username or parsed.password:
        raise ValueError('only public HTTP/HTTPS URLs without credentials are allowed')
    port = parsed.port or (443 if parsed.scheme == 'https' else 80)
    if port not in (80, 443):
        raise ValueError('non-web destination port refused')
    host = parsed.hostname
    try:
        addresses = [str(ipaddress.ip_address(host))]
    except ValueError:
        infos = await asyncio.get_running_loop().getaddrinfo(host, port, type=socket.SOCK_STREAM)
        addresses = list(dict.fromkeys(info[4][0] for info in infos))
    if not addresses:
        raise ValueError('destination did not resolve')
    for address in addresses:
        ip = ipaddress.ip_address(address)
        if isinstance(ip, ipaddress.IPv6Address) and ip.ipv4_mapped:
            ip = ip.ipv4_mapped
        if not ip.is_global:
            raise ValueError('non-public destination refused')
    # httpcore connects to this literal; there is no second hostname DNS lookup.
    target = httpx.URL(url).copy_with(host=addresses[0])
    authority = parsed.netloc
    return target, authority, host


async def public_fetch(url):
    from trading_r1.tools.web.visit_fetch import FetchedPage, _extract_pdf, _parse
    from trading_r1.state.runtime_config import runtime_config

    original = url
    try:
        async with asyncio.timeout(runtime_config().fetch.timeout_s):
            async with httpx.AsyncClient(follow_redirects=False, trust_env=False) as client:
                for _ in range(6):
                    target, authority, host = await public_target(url)
                    async with client.stream('GET', target, headers={'Host': authority, 'User-Agent': 'Kernscale-Trading-Research/0.1'},
                                             extensions={'sni_hostname': host}) as response:
                        if response.is_redirect:
                            location = response.headers.get('location')
                            if not location:
                                raise ValueError('redirect missing destination')
                            url = urljoin(url, location)
                            continue
                        response.raise_for_status()
                        body = bytearray()
                        async for chunk in response.aiter_bytes():
                            body.extend(chunk)
                            if len(body) > MAX_BODY:
                                raise ValueError('page too large')
                        content_type = response.headers.get('content-type', '')
                        encoding = response.encoding or 'utf-8'
                    if 'pdf' in content_type.lower():
                        text = await _parse(_extract_pdf, bytes(body))
                    else:
                        import trafilatura
                        text = await _parse(trafilatura.extract, body.decode(encoding, errors='replace'))
                    return FetchedPage(url=original, text=text or '', ok=bool(text), error=None if text else 'no extractable content')
                raise ValueError('too many redirects')
    except Exception:
        # Do not reflect private URLs, credentials or transport diagnostics into model reports.
        return FetchedPage(url=original, text='', ok=False, error='public page unavailable or destination refused')
