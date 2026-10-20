from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]
VERIFIER = ROOT / ".claude" / "skills" / "verify-capstan"


class VerifyCapstanContractTests(unittest.TestCase):
    def test_active_verifier_uses_one_shared_machine_credential_for_postmaster_and_work(self) -> None:
        active_text = "\n".join(
            path.read_text(encoding="utf-8")
            for path in VERIFIER.rglob("*")
            if path.is_file()
        )

        self.assertNotIn("capstan.postmaster.poll", active_text)
        self.assertNotIn("capstan.work.drain", active_text)
        self.assertIn('"$B/capstan.py" token capstan.machine', (VERIFIER / "SKILL.md").read_text())
        self.assertIn(
            '"$B/capstan.py" api capstan.machine POST /api/v1/poll',
            (VERIFIER / "features" / "postmaster.md").read_text(),
        )
        self.assertIn(
            'Preconditions: baseline; `"$B/capstan.py" token capstan.machine`;',
            (VERIFIER / "features" / "work.md").read_text(),
        )
        self.assertIn(
            'tok="${STATE}/tokens/capstan.machine"',
            (VERIFIER / "bin" / "drain-config.sh").read_text(),
        )
        settings = (VERIFIER / "features" / "settings-and-credentials.md").read_text()
        self.assertIn("personal-credentials-issue-option` ×2", settings)
        self.assertIn("shared machine token drives the drain's `--dry-claim`", settings)
        self.assertIn(
            "Purposes: capstan.artifact.ingest, capstan.machine.",
            (VERIFIER / "bin" / "capstan.py").read_text(),
        )


if __name__ == "__main__":
    unittest.main()
