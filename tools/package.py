"""Public installation packaging entry point."""

from runpy import run_path
from pathlib import Path

run_path(str(Path(__file__).with_name("release.py")), run_name="__main__")
