# Sulu Touch 2026: What is new in Sulu 3.1

## Setup

```bash
git clone git@github.com:sulu/sulu-touch-2026-sulu-3.1.git
cd sulu-touch-2026-sulu-3.1
docker compose up -d
composer install
bin/adminconsole sulu:build dev
symfony serve
```

Logins: `admin` / `admin` (all permissions) and `user` / `user` (cannot publish pages and articles).

## Steps

- [Step 1: Symfony 8](https://github.com/sulu/sulu-touch-2026-sulu-3.1/pull/1)
- [Step 2: Snippets template groups](https://github.com/sulu/sulu-touch-2026-sulu-3.1/pull/2)
- [Step 3: Text editor configs](https://github.com/sulu/sulu-touch-2026-sulu-3.1/pull/3)
- [Step 4: Media content locales and parallel image generation](https://github.com/sulu/sulu-touch-2026-sulu-3.1/pull/4)
- [Step 5: Content workflow and review](https://github.com/sulu/sulu-touch-2026-sulu-3.1/pull/5)
- [Step 6: Notifications with deep links](https://github.com/sulu/sulu-touch-2026-sulu-3.1/pull/6)
- [Step 7: Preview to block](https://github.com/sulu/sulu-touch-2026-sulu-3.1/pull/7)
