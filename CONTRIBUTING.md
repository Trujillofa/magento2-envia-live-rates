# Contributing

1. Fork and branch from `main`.
2. Keep Magento module defaults **safe** (`envia` and `dtlocalfree` inactive).
3. Do not commit API tokens, credentials, or store-specific secrets.
4. Run tests: `python3 -m pytest tests/ -v`
5. Prefer small PRs: one feature or fix per PR.
6. Describe Magento version tested.

## Code style

- PHP: `declare(strict_types=1);`, Magento coding standards where practical  
- Python diagnostics: Black-friendly, no hard-coded secrets  
