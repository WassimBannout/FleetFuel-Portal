# Technical references and verification

Checked on 2026-09-28. Package patches, CLI behavior and hosting offers may change; re-check before changing versions or choosing deployment. Original employer research is preserved separately and has not been independently reverified here.

| Source | Use |
| --- | --- |
| [Claude Code project memory](https://code.claude.com/docs/en/memory) | Repository-root CLAUDE.md persistent instructions |
| [Claude Code quickstart](https://code.claude.com/docs/en/quickstart) | Launching from a project and continuing sessions |
| [Laravel 13 release notes](https://laravel.com/docs/13.x/releases) | PHP 8.3 minimum and framework baseline |
| [Laravel Fortify](https://laravel.com/docs/13.x/fortify) | Authentication backend and custom login views |
| [Laravel Sanctum](https://laravel.com/docs/13.x/sanctum) | API tokens; use abilities together with authorization |
| [Laravel database documentation](https://laravel.com/docs/13.x/database) | Database transactions and SQL Server driver requirements |
| [Laravel Breeze repository](https://github.com/laravel/breeze) | Inspect compatibility instead of blindly applying old scaffolding commands |
| [ExchangeRate-API open endpoint](https://www.exchangerate-api.com/docs/free) | Keyless USD feed, daily update, caching, rate limits and attribution requirement |

The exchange-rate provider requires a link on pages using its rates. Use the prescribed text **Rates By Exchange Rate API** linking to `https://www.exchangerate-api.com`. Cache responses and avoid publishing the provider's full rate feed. The open endpoint supplies latest observations; it is not a historical-data service. Historical demonstration records therefore need explicit synthetic fixtures, not fabricated historical downloads.

The project-specific rules for quotas, price precision, retry semantics, expiry and role permissions are original implementation choices recorded in DECISIONS.md. They are not claimed to be mandated by the framework or provider.
