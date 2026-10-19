---
name: feedback-nativephp-conditional-nodes-need-keys
description: "In NativePHP mobile Blade screens, every direct child of a container that can gain/lose a conditional node MUST have native:key, or Android crashes with a Compose ClassCastException"
metadata:
  node_type: memory
  type: feedback
---

In the NativePHP mobile app, any `<native:*>` container whose children can
change — an `@if` error message, an `@elseif` branch, a polled countdown that
disappears — must give **every direct child** an explicit `native:key="..."`.
Keying only the parent is NOT enough.

**Why:** without a key, `Element::toArray()` assigns node ids from a positional
`$nextId++` counter (`vendor/nativephp/mobile/src/Edge/Element.php`). Inserting
one conditional node shifts the id of every node after it. Android renders each
node inside `key(node.id)` (`NodeView.kt`) and `FlexColumn` iterates children
with a bare `forEachIndexed` — no `key()` of its own. After the shift, Compose
reuses the previous composition's remembered slots for a *different node type*
and dies:

```
java.lang.ClassCastException: androidx.compose.runtime.ParcelableSnapshotMutableState
  cannot be cast to androidx.compose.runtime.internal.ComposableLambdaImpl
  at ComposableLambdaKt.rememberComposableLambda(ComposableLambda.kt:1373)
  at NodeViewKt.NodeView(NodeView.kt:291)
```

This was a real reproduced crash: Reset Password with mismatched passwords,
where the inserted error `<native:text>` pushed a `<native:pressable>` down one
id slot (a pressable remembers more state than a text, hence the cast failure).
Screens that looked fine were only lucky — the shifted nodes happened to be
type-compatible.

**How to apply:** when writing or editing any screen, give every direct child of
the root container a stable `native:key` — including the ones inside `@if` /
`@elseif` blocks. Names must be unique among siblings and describe the node
(`heading`, `email`, `email-error`, `notice`, `submit`). Never use the loop index
as a key; use a stable domain id.

**Regression caught the same day — from automating the fix.** Bulk-adding
`native:key` with a find-and-insert script put the attribute OUTSIDE some tags
(the anchor already included the closing `>`), and in three places inside a
Blade `{{ }}` ternary — a PHP syntax error. The Android build still reported
BUILD SUCCESSFUL (Gradle doesn't validate Blade), so it was only caught by
re-reading the files.

**Going forward:** never bulk-insert a Blade attribute with a string anchor that
might include the tag's closing `>`. After any bulk edit, grep every touched
file for `"> native:key`, `</native:.*native:key` and
`native:key="[a-z-]*"[a-zA-Z{]` — all three must return nothing — and
force-render every touched view via `php artisan tinker`
(`view('native.x', [...])->render()`) before rebuilding the APK.
