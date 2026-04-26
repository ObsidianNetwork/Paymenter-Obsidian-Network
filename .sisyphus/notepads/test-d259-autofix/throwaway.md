# D.259 autofix throwaway

This file exists solely to validate the autofix skill end-to-end.

It contains two deliberate issues CodeRabbit will catch:

1. The word "soley" above is a typo (should be "solely").
2. The code block below is missing a language tag (markdownlint MD040).

```bash
echo "this fence has no language tag"
```

This file will be deleted after the autofix skill is verified.
