"""Test-only factory for the isolated, provider-unavailable HTTP contract."""
import os

from bridge.app import create_app
from bridge.store import Store


def create_offline_app():
    return create_app(store=Store(os.environ['TRADING_BRIDGE_DB']))
