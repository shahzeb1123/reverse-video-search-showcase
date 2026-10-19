# AI-Assisted Workflow

I built Reverse Video Search largely on my own, with **Claude Code** as an AI
pair-engineer. This document explains how I worked with it. The point isn't
that AI wrote code. It's how I kept a months-long project consistent, correct
and reviewable while moving at a small team's pace.

## The loop

1. **Describe the feature with its constraints.** I give the behaviour, the
   edge cases I already know about, and any rules ("this must not touch the
   footer", "campaigns are separate from offers").
2. **Review the plan before any code.** Claude Code reads the relevant code and
   proposes an approach. I correct the design at this stage, because that's
   where corrections cost the least.
3. **Implement across layers.** Migrations, models, services, controllers,
   jobs, Blade views and tests, all in one change.
4. **Tests prove behaviour.** Feature tests drive the real routes, so a change
   is accepted because it behaves correctly, not because the code looks right.
5. **I review every diff and commit it** with a message that says what changed
   and why.
6. **Lessons go into memory.** Every correction, gotcha and decision is written
   down so the next session doesn't repeat the mistake.

## Persistent project memory

The private repository has a `memory/` folder with 32 files and an index
(`MEMORY.md`) that every session reads first. There are three kinds of entries:

| Type | Purpose | Example |
|---|---|---|
| **Feedback** | A rule learned the hard way, with the *why* and *how to apply it* | Never edit an already-run migration; never hardcode quota numbers in copy |
| **Project state** | Where a multi-session feature stands: architecture, files, what's verified, what's pending, deploy steps | Email campaigns module, mobile app status, payment gateway decisions |
| **Reference** | Findings from third-party docs or debugging that are expensive to rediscover | NativePHP renderer constraints, provider rate-limit behaviour |

Why it matters:
- **Continuity.** A feature like the Android app spanned weeks. Each session
  resumed from the recorded state instead of re-reading the codebase from scratch.
- **Corrections stick.** When I correct the agent ("campaigns must not depend
  on the Offers module"), the correction is saved and followed from then on.
- **Real root causes.** Entries record the actual failure. For example, a
  Compose `ClassCastException` traced to positional node ids in the NativePHP
  renderer, and a regression caused by a bulk-edit script. They also record
  the check that would have caught it.

Sanitized samples: [claude-memory-samples/](../claude-memory-samples)

## What AI accelerated

- **Breadth beyond my core stack.** Computer-vision fingerprinting in Python,
  a native Android app, Google Play billing through RevenueCat. Areas that
  would each have taken weeks of ramp-up became days.
- **Integration work.** Adding a payment gateway end-to-end (checkout,
  webhooks, idempotency, renewals, tests) went from about a week to one or two
  days.
- **Test coverage.** 590 tests exist because writing them stopped being the
  slow part.
- **Debugging production issues.** Reading logs, forming hypotheses and
  writing a reproducing test first.

## What stayed human

- Product decisions, pricing and scope.
- Architecture: service boundaries, which queue a job runs on, what lives in
  Python and what in Laravel.
- Security judgement. For example, the SSRF rule documents its remaining
  risk (DNS rebinding) instead of claiming to be complete.
- Final review of every change, and every deploy.
