"""Keep user-facing version notices aligned across both language editions."""

import json
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parent.parent


class DocumentationVersion(unittest.TestCase):
    def test_current_guides_identify_the_exact_package_version(self):
        version = json.loads((ROOT / "package.json").read_text())["version"]
        for name in [
            "README.md",
            "README.en.md",
            "docs/GUIDE.md",
            "docs/GUIDE.en.md",
            "docs/CONFIGURATION.md",
            "docs/CONFIGURATION.en.md",
        ]:
            with self.subTest(document=name):
                text = (ROOT / name).read_text()
                marker = re.match(r"<!-- wp-xp-core-version: ([^ ]+) -->", text)
                self.assertIsNotNone(marker)
                self.assertEqual(marker[1], version)
                # The introductory text must agree, not just the machine-readable marker.
                intro = text.split("\n", 2)[2][:4000]
                notices = re.findall(r"\*\*(\d+\.\d+\.\d+(?:-(?:alpha|beta|rc)\.\d+)?)", intro)
                self.assertTrue(notices)
                self.assertEqual(notices[0], version)
