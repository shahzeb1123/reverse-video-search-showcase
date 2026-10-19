# Claude Code Memory: Samples

These are four entries from the project's `memory/` folder (32 files in the
private repository). Claude Code reads the index at the start of each session,
so the rules and context below carry across sessions.

They are lightly sanitized: server names, providers, local paths and business
figures were removed. The structure and wording are otherwise as written.

| File | Type | What it shows |
|---|---|---|
| [feedback_migrations.md](feedback_migrations.md) | Feedback | A production mistake turned into a permanent rule, with *why* and *how to apply it* |
| [feedback_no_hardcoded_quota_copy.md](feedback_no_hardcoded_quota_copy.md) | Feedback | A product-integrity bug (copy promised more than the backend granted) and the design rule that prevents it |
| [feedback_nativephp_conditional_nodes_need_keys.md](feedback_nativephp_conditional_nodes_need_keys.md) | Feedback | A root-caused Android crash, plus the regression caused by automating the fix and the checks added afterwards |
| [email_campaigns_module.md](email_campaigns_module.md) | Project state | How a multi-session feature is documented: architecture, the owner's corrections, deploy requirements, follow-ups |

Each feedback entry follows the same pattern: **the rule → why (the real
incident) → how to apply it**. A rule without its reason gets misapplied, and
the reason is what lets the agent judge new edge cases.
