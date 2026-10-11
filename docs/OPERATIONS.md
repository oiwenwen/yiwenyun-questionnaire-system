# 运维手册

面向日常维护、排错和数据安全。部署步骤见 [DEPLOY.md](DEPLOY.md)。

---

## 1. 环境要求

| 组件 | 版本 | 备注 |
|---|---|---|
| PHP | 7.3+（开发环境 7.4） | 需要 `pdo_mysql`、`mbstring`、`session` 扩展 |
| MySQL | 5.7（8.0 亦可） | 字符集必须 `utf8mb4` |
| Web 服务器 | Apache / Nginx / OpenResty | 无 rewrite 需求 |

## 2. 本地开发

三种方式任选：

- **集成环境**：phpstudy / XAMPP / Laragon，站点根目录指向本项目目录
- **PHP 内置服务器**：`php -S 127.0.0.1:8080 -t .`
- **便携组合**：下载 PHP（Windows 用 NTS 版本）与 MySQL 免安装包，解压后手动指定 `--basedir` / `--datadir` 启动，
  适合不能装服务、拿不到管理员权限的机器

## 3. 配置

所有数据库与开关配置集中在 `config.php`（模板见 `config.example.php`）：

| 键 | 说明 |
|---|---|
| `host` / `port` | 数据库地址。**容器化部署时不要写 `localhost`** |
| `dbname` / `username` / `password` | 库名与凭据 |
| `allow_register` | 是否允许访客自助注册。**公网部署建议设为 `false`** |

`config.php` 含明文口令，已列入 `.gitignore`，切勿提交。

## 4. 数据库关键约定

**⚠️ 答卷时间列必须叫 `submit_time`。**
后端三个接口（`submit_response.php`、`get_responses.php`、`export_results.php`）
与前端（`index.html` 渲染结果表）都依赖这个列名。若列名不同，提交答卷会直接抛 SQL 错误。

排查方法：

```sql
SHOW COLUMNS FROM responses;
-- 若发现列名是别的（例如 submitted_at），改名即可：
ALTER TABLE responses CHANGE COLUMN submitted_at submit_time DATETIME DEFAULT CURRENT_TIMESTAMP;
```

**⚠️ 所有表都是 MyISAM**，没有外键、没有级联。因此：

- 删除问卷必须走 `api/delete_survey.php`（它会在事务内按
  `answers → responses → options → questions → surveys` 顺序清理）；
  直接在数据库里 `DELETE FROM surveys` 会留下大量孤儿数据
- 检查是否已有孤儿数据：

```sql
SELECT COUNT(*) FROM options  WHERE question_id NOT IN (SELECT id FROM questions);
SELECT COUNT(*) FROM answers  WHERE response_id NOT IN (SELECT id FROM responses);
```

## 5. 日常维护工具

### 备份数据库

`_restore/tools/backup_database.ps1`（Windows / PowerShell）：

```powershell
powershell -ExecutionPolicy Bypass -File _restore/tools/backup_database.ps1 -BackupDir E:\db_backup -Keep 14
```

- 自动读取 `config.php` 获取连接信息
- 通过临时 defaults 文件传口令，不会出现在命令行与进程列表里
- 生成 `yiwenyun_年月日_时分秒.sql`，并按数量自动清理旧备份

加入计划任务（每天 03:00）：

```powershell
schtasks /Create /TN "yiwenyun-backup" /SC DAILY /ST 03:00 /TR "powershell -ExecutionPolicy Bypass -File <项目路径>\_restore\tools\backup_database.ps1"
```

Linux 服务器上等价的一条命令：

```bash
mysqldump -u <用户> -p --default-character-set=utf8mb4 --databases yiwenyun | gzip > /root/backup/yiwenyun_$(date +%F).sql.gz
```

### 重置用户密码

`_restore/tools/reset_password.php`（命令行，只修改指定用户的密码与角色）：

```bash
php _restore/tools/reset_password.php <用户名> <新密码> [admin|editor|viewer]
```

该脚本拒绝通过网页访问（`PHP_SAPI !== 'cli'` 时直接返回 403）。

## 6. 备份策略建议

仅本机备份是不够的——服务器本身也可能整台消失。建议分层：

| 层级 | 内容 | 频率 |
|---|---|---|
| 数据库 | 上述脚本导出 `.sql` | 每天 |
| 转储文件 | 复制到另一块盘 / 网盘 / 对象存储 | 每天 |
| 云端 | 云厂商**自动快照策略** | 每天或每周，保留 7–30 天 |
| 代码 | 推送到 Git 远程仓库 | 每次改动 |

> 注意：快照通常与实例生命周期绑定，实例释放后快照也可能一并消失。
> **真正保命的是每天导出到本地或对象存储的那份 SQL。**

## 7. 常见问题排查

| 现象 | 排查方向 |
|---|---|
| 页面加载后一直报 `JSON` 解析失败 | 接口是否输出了两段 JSON，或 PHP Notice/Warning 混进了响应体。检查是否有重复的 `session_start()` |
| 无法登录，提示用户名或密码错误 | 确认用户 `status='active'`；必要时用 `reset_password.php` 重置 |
| 提交答卷报错 | 见第 4 节的 `submit_time` 约定 |
| 未发布问卷被外人填写 | 检查是否漏了状态校验；草稿应仅作者本人可读 |
| 删除问卷后数据库越来越臃肿 | 孤儿数据，见第 4 节 |
| 中文乱码 | 库/表/连接三处字符集都要是 `utf8mb4` |
| 导出 CSV 用 Excel 打开乱码 | 导出的响应需带 UTF-8 BOM（本项目已处理） |

## 8. 安全建议

- 公网部署时把 `allow_register` 设为 `false`，账号由管理员开通
- 修改所有默认口令；数据库口令使用足够长度并避免跨环境复用
- 全站启用 HTTPS 并开启强制跳转
- 定期检查服务器上是否残留调试脚本（任何能输出密码哈希、`phpinfo()` 的文件都必须删除）
- 日志中不得记录明文密码或完整凭据

## 9. 数据迁移

**从旧环境迁移到新环境：**

1. 旧环境导出：`mysqldump -u root -p --default-character-set=utf8mb4 --databases yiwenyun --result-file=yiwenyun.sql`
2. 新环境导入：面板「数据库 → 导入」，或 `mysql -u <用户> -p yiwenyun < yiwenyun.sql`
3. 注意检查第 4 节的列名约定；跨版本迁移（尤其 5.7 → 8.0）后建议 `ANALYZE TABLE` 一次

**只有数据文件（`.frm` / `.MYD` / `.MYI`）没有 SQL 时的抢救方式：**

1. 安装**与数据文件同版本**的 MySQL（MyISAM 的表结构靠 `.frm` 识别，跨大版本会失败）
2. 初始化一个全新数据目录：`mysqld --initialize-insecure --basedir=<安装目录> --datadir=<新数据目录>`
3. 停止服务，把数据库目录整个复制进 `<新数据目录>`（**只复制业务库，不要覆盖 `mysql` 系统库**，否则会带过去旧的 root 口令）
4. 启动服务，用 `mysqldump` 立刻导出成 SQL —— 此后就以 SQL 为迁移标准
