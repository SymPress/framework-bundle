# Native debug-container dumps

`SymPressFrameworkBundle::build()` already calls the native Symfony
`FrameworkBundle::build()`. This registers Symfony's
`ContainerBuilderDebugDumpPass`, so the Symfony 8.2 optimization is inherited when
a stable 8.2 version is installed through the existing `^8.1` dependency ranges.
The upstream change is in that compiler pass, not in `PhpDumper`. No additional
SymPress cloning or dumping implementation is needed.

The existing package tests exercise native framework service construction and
fresh debug-container reconstruction, including an optimized snapshot. The
native 8.2 dump pass has also been checked with synthetic environment parameters
and a shared service definition: it produces usable XML/serialized dumps without
mutating the original service arguments. That is a compatibility check, not a
SymPress performance measurement.

Symfony 8.2 is not yet stable as of 2026-10-09. This release retains stable
dependencies and does not claim the upstream speed improvement is active on 8.1.
The later native compile-time environment log can be inspected on an explicit
fresh build as described in the Kernel's
[diagnostic guide](https://github.com/SymPress/kernel/blob/main/docs/compile-time-environment.md).
Framework's container debug command uses Symfony's native descriptors; Kernel's
own command lists remaining placeholders and is a separate implementation.

Sources: [Symfony PR 66343](https://github.com/symfony/symfony/pull/66343),
[official performance article](https://symfony.com/blog/new-in-symfony-8-2-performance-improvements).
