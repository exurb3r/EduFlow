---
paths:
  - rector.php
---

# Rector Php

## Rector naming set breaks named-arg constructors
Rector's `naming: true` prepared set renames constructor-promoted params to match type names, which breaks every named-argument caller and silently changed resolve()'s return type/PHPDoc. Do not re-enable it; also audit rector's removed named arguments (e.g. it dropped a required `type:` arg from CircleWalletService::executePayment) after each run and re-run the full test suite.
