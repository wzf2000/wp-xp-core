"""Verify real catalogs, product-string coverage and packaged language payloads."""

import gettext
import importlib.util
import json
import io
from pathlib import Path
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parent.parent


class Languages(unittest.TestCase):
    def test_catalogs_compile_and_translate_without_touching_user_text(self):
        subprocess.run(["python3", "tools/languages.py", "--check"], cwd=ROOT, check=True)
        en = gettext.GNUTranslations(
            io.BytesIO((ROOT / "languages/wp-xp-core-en_US.mo").read_bytes())
        )
        zh = gettext.GNUTranslations(
            io.BytesIO((ROOT / "languages/wp-xp-core-zh_CN.mo").read_bytes())
        )
        self.assertEqual(en.gettext("我的成长"), "My growth")
        self.assertEqual(zh.gettext("我的成长"), "我的成长")
        self.assertEqual(en.gettext("User supplied 经验规则"), "User supplied 经验规则")
        for path in ROOT.glob("*.php"):
            for text in re.findall(r"wp_xp_core_t\('([^'\\]*)'\)", path.read_text()):
                self.assertTrue(text in en._catalog, (path.name, text))
                self.assertTrue(en.gettext(text))
        js = json.loads((ROOT / "languages/js-strings.json").read_text())
        for text in js:
            self.assertIn(text, en._catalog)

    def test_language_and_block_files_are_installation_payload(self):
        spec = importlib.util.spec_from_file_location("xp_release", ROOT / "tools/release.py")
        release = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(release)
        files = release.source_files()
        for name in [
            "languages/wp-xp-core-en_US.po",
            "languages/wp-xp-core-en_US.mo",
            "languages/wp-xp-core-en_US.json",
            "languages/wp-xp-core.pot",
            "modules/blocks.php",
            "notification-sources.php",
            "reward-controls.php",
            "bbpress.php",
        ]:
            self.assertIn(name, files)
            self.assertTrue(release.installation_name(name))
        self.assertFalse(release.installation_name("languages/private.txt"))
