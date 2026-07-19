# 种火集结号 / Fireseed Engage

《常磐大逃杀》的独立策略游戏模式：城市建设、武将与技能卡成长、资源经营、领地争夺和赛季重建。

An independent strategy mode for Tokiwa Battle Royale, featuring city building, general and skill-card progression, resource management, territorial warfare, and seasonal world reconstruction.

## 运行要求 / Requirements

- PHP 7.4 或 8.2，启用 `mysqli`、`mysqlnd`、`mbstring`、`json` 与 `session`
- MySQL 8+ 或 MariaDB 10.5+
- Apache 2.4（推荐启用 `mod_rewrite` 与 `mod_headers`）或具有等价访问限制的 Nginx
- HTTPS，用于任何非本机内测环境
- 每分钟运行一次 PHP 定时任务的能力

PHP 7.4 or 8.2 is supported. The application requires MySQL/MariaDB, the extensions listed above, HTTPS outside localhost, and a scheduler capable of invoking PHP once per minute.

## 全新安装 / Fresh installation

1. 创建空数据库和仅对该数据库拥有权限的数据库账户。
2. 将站点根目录指向本仓库，并确认服务器会阻止直接访问 `config/`、`doc/`、`includes/`、`logs/`、`sql/`、`tests/` 和 `tools/`。
3. 无论本机还是远程安装，都必须先设置高强度一次性环境变量 `FIRESEED_INSTALL_TOKEN`，然后通过 HTTPS 直接访问不带参数的 `install.php`，在授权表单中提交令牌。令牌只通过 POST 传输，成功授权后会轮换会话并原子标记为已消费；看到环境检查页后立即撤销该环境变量。仅在直接回环地址进行本机开发且无法提供 TLS 时，可临时设置 `FIRESEED_ALLOW_INSECURE_LOCAL_INSTALL=true`，绝不能在共享或代理环境开启。
4. 完成环境检查、数据库配置和管理员创建。安装器会把机密写入被 Git 忽略的 `config/local.php`，并创建 `config/installed.lock`。
5. 安装完成后，在 Web 服务器层禁用或移除 `install.php` 的访问。
6. 配置每分钟执行：

   ```bash
   php /absolute/path/to/fireseed-engage/cron_tasks.php
   ```

Every browser installation requires a high-entropy `FIRESEED_INSTALL_TOKEN`, including localhost access. Open `install.php` over HTTPS without query parameters and submit the token through its POST form; revoke the environment variable as soon as authorization succeeds. `FIRESEED_ALLOW_INSECURE_LOCAL_INSTALL=true` is an explicit loopback-only development escape hatch and must never be enabled on a shared or proxied deployment. The installer is rerunnable until its lock file is created. It writes deployment secrets only to the untracked `config/local.php`; the tracked configuration contains safe defaults and environment-variable support.

若授权后会话丢失且安装尚未完成，应先确认没有其他安装进程，再删除 `config/.install-token-consumed`、设置一个全新的令牌并重新授权。不要复用旧令牌。

If the authorized session is lost before installation completes, first verify that no installer is running, then remove `config/.install-token-consumed`, set a new token, and authorize again. Never reuse the previous token.

已经存在 `config/installed.lock` 时，不要只删除锁文件并在原数据库上就地重装。应先备份，确认没有安装或定时任务进程，准备经过核验的空数据库，并同时按恢复流程处理本地配置与安装授权标记。

When `config/installed.lock` exists, do not delete only that file and reinstall over the live database. Back up first, verify that installer and cron processes are stopped, use a confirmed empty database, and reset local configuration and authorization markers through the documented recovery procedure.

## 配置 / Configuration

复制 `config/local.php.example` 为 `config/local.php` 可进行手工部署。环境变量优先于本地文件，名称是在配置键前增加 `FIRESEED_`，例如：

- `FIRESEED_DB_HOST`
- `FIRESEED_DB_USER`
- `FIRESEED_DB_PASS`
- `FIRESEED_DB_NAME`
- `FIRESEED_SITE_URL`
- `FIRESEED_ADMIN_EMAIL`
- `FIRESEED_DEBUG_MODE`，内测部署必须为 `false`
- `FIRESEED_TRUST_PROXY_HEADERS`，仅在可信反向代理会覆盖转发头时开启
- `FIRESEED_SESSION_LIFETIME`

应用、安装器、迁移与数据库会话固定使用 `Asia/Shanghai` / `+08:00`，数据库字符集固定为 `utf8mb4`；这两项不是部署时可变配置。

Copy `config/local.php.example` to `config/local.php` for manual deployment. Environment variables override the listed local values. The application uses a fixed `Asia/Shanghai` / `+08:00` timezone and `utf8mb4` database character set. Never commit `config/local.php`, an installation lock, logs, or database backups.

## 既有数据库升级 / Existing database upgrade

升级前先把旧版 `config/config.php` 中的真实数据库、站点和管理员配置迁移到不受版本控制的 `config/local.php`（POSIX 权限 `0600`）或对应 `FIRESEED_*` 环境变量，并在切换代码前验证新配置确实连接预期数据库。保留 `config/installed.lock`，或持续在 Web 服务器层封锁 `install.php`。随后开启维护模式、暂停 `cron_tasks.php` 调度，并备份数据库、代码版本、`config/local.php` 与运行环境配置。按以下顺序执行尚未执行过的脚本：

1. `sql/upgrade_20260717_gameplay_expansion.sql`
2. `sql/upgrade_20260717_card_pool_resources.sql`
3. `sql/upgrade_20260718_image_resources.sql`
4. `sql/upgrade_20260719_world_season.sql`
5. `sql/upgrade_20260719_research_economy.sql`

升级脚本设计为可重复执行，但上线前仍应在数据库副本上演练。完成后运行测试和冒烟流程，确认无误后恢复定时任务并关闭维护模式。若升级失败，不要尝试手工执行降级 SQL；保持定时任务暂停，并恢复代码版本和升级前数据库快照。

Before switching code on a legacy deployment, move the old tracked database/site/admin settings into untracked `config/local.php` (mode `0600` on POSIX) or matching `FIRESEED_*` variables, verify the target database, and preserve `config/installed.lock` or keep the installer blocked at the web server. Then enable maintenance mode, stop cron, and back up the database, code, local configuration, and runtime environment. Apply only pending migrations in the order above, validate on a copy first, and resume cron only after smoke testing. If rollback is required, keep cron stopped and restore both code/configuration and the pre-upgrade snapshot.

## Web 服务器保护 / Web-server protection

Apache 会读取仓库根目录的 `.htaccess`。Nginx 必须配置等价规则，例如：

```nginx
location ~ ^/(config|doc|includes|logs|sql|tests|tools)(/|$) {
    deny all;
}

location ~ /\. {
    deny all;
}
```

生产环境还应限制 `install.php`，将 PHP 错误写入服务器日志，并在可信代理终止 TLS 时显式设置 `FIRESEED_TRUST_PROXY_HEADERS=true`。不要仅依赖应用页面隐藏内部文件。

Apache uses the repository `.htaccess`. Nginx and other servers must mirror these deny rules and block the installer after setup.

## 验证 / Verification

项目没有需要编译的前端构建步骤。提交或部署前运行：

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
Get-ChildItem tests -Filter *.php | ForEach-Object { php $_.FullName }
Get-ChildItem assets -Recurse -Filter *.js | ForEach-Object { node --check $_.FullName }
```

仓库的 GitHub Actions 会在 PHP 8.2 上执行同类语法和规则回归检查。静态测试不能替代连接真实 MySQL/MariaDB 的安装、注册、建城、出征、赛季重置和恢复演练。

There is no frontend build step. Run PHP lint, every lightweight test, and JavaScript syntax checks before deployment. A real database staging exercise remains mandatory.

## 内测方向 / Internal-beta focus

首轮小规模内测应验证完整循环和数据边界，而不是追求最终数值平衡：

- 新账号、登录节流、关闭注册、人数上限、维护模式和安全登出
- 主城建设、四色赛季经济、亮／夜低速长期积累与卡池消费
- 赛季科研的即时效果，以及永久科研带来的跨赛季上限成长
- 全图基础信息可见、侦察保护详细军情、空地免费与资源地思考回路占领
- 放弃资源地或改建分基地时的全额思考回路返还
- 十二门、中央银白之孔、胜利冻结和一次性原子赛季重建
- 武将、技能卡、亮／夜、永久科研和同盟身份保留；赛季资产正确清空
- 并发注册、卡池抽取、领地变更和赛季切换时不存在重复奖励或部分提交

卡池价格、科研系数、资源权重与生产速度目前是集中配置的临时基准，应使用内测遥测和玩家反馈另开数值专项，不在本轮把它们视为最终平衡。

The first closed beta should validate the end-to-end loop, persistence boundaries, concurrency safety, and seasonal reconstruction. Current economy and combat numbers are provisional baselines for a later balance pass.

权威机制边界见 `doc/internal-beta-readiness-spec-20260719.txt`。

See `doc/internal-beta-readiness-spec-20260719.txt` for the authoritative persistence, economy, research, map, and season rules.
