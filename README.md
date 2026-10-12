# 易问云问卷系统

一个轻量的在线问卷系统：注册登录、可视化设计问卷、生成链接发布分享、在线填写、查看结果、导出 CSV。
纯 PHP + MySQL 手写实现，**无框架、无 Composer 依赖、无前端构建步骤**，解压即用。

## 在线演示

**http://115.159.38.76**

部署在腾讯云轻量应用服务器（2 核 2G · 上海），运行环境为
**1Panel + OpenResty + MySQL 8.0 + PHP 8.0**，已配置每日数据库自动备份，
并以 `tests/acceptance_test.ps1` 做过全链路端到端验收。

> 后台需要账号登录（**已关闭自助注册**，避免公网上被批量注册）。
> 管理员发布问卷后，分享给填写者的链接形如 `http://115.159.38.76/fill.html?id=N`。

## 功能特性

- **账号体系** —— 注册 / 登录 / 退出；角色分 `admin`（管理员）、`editor`（编辑者）、`viewer`（只读）；密码使用 `password_hash()` 的 bcrypt 哈希存储
- **问卷设计** —— 单选、多选、文本三种题型；可设必答、可增删排序；草稿与已发布分离，已发布问卷锁定不可再改
- **发布分享** —— 一键生成 `fill.html?id=N` 填写链接，直接发给填写者，无需对方注册
- **在线填写** —— 移动端自适应；前端 + 后端双重必答校验；只接受属于本问卷的题目，拒绝脏数据
- **结果查看** —— 按题目纵向展示每份提交，含提交时间与来源 IP
- **数据导出** —— 一键导出 CSV，带 UTF-8 BOM，Excel 打开中文不乱码

## 系统流程

```
   管理员端                                    填写者端
      │                                           │
      │ 登录 / 设计问卷 / 发布                     │ 打开分享链接
      ▼                                           ▼
  index.html  ──────────►  api/*.php  ◄──────  fill.html?id=N
                            │  │  │
              会话鉴权 ◄────┘  │  └────► 参数与归属校验
                              │
                              ▼
                    PDO 预处理（无 SQL 拼接）
                              │
                              ▼
              MySQL · utf8mb4 · 6 张表（MyISAM）
                              │
                              ▼
                    结果查看 / CSV 导出（带 BOM）
```

## 技术栈

| 层 | 说明 |
|---|---|
| 后端 | PHP 7.3+（开发环境 7.4），PDO + 全量预处理语句，无 SQL 拼接 |
| 数据库 | MySQL 5.7，字符集 `utf8mb4`，MyISAM 引擎 |
| 前端 | 原生 HTML / CSS / JavaScript，无框架无构建；SweetAlert2 走 CDN |
| 会话 | PHP 原生 Session，同源 Cookie |
| 部署 | 兼容 phpstudy / XAMPP 传统站点，也适配 1Panel + OpenResty + PHP 容器化部署 |

## 快速开始

### 1. 配置数据库

```bash
cp config.example.php config.php
```

编辑 `config.php`：

```php
return [
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'dbname'   => 'yiwenyun',
    'username' => 'root',
    'password' => '你的口令',
    'allow_register' => true,   // 部署到公网前建议改为 false
];
```

> `config.php` 含明文口令，已在 `.gitignore` 中排除，不会被提交。

### 2. 建表

```bash
mysql -u root -p < _restore/schema.sql
```

### 3. 启动

**方式一：集成环境（推荐）**
phpstudy / XAMPP / Laragon，站点根目录指向本项目目录，PHP 7.3+，MySQL 5.7。

**方式二：PHP 内置服务器（本地开发用）**

```bash
php -S 127.0.0.1:8080 -t .
```

### 4. 创建管理员

首页点「注册」建个账号，然后升为管理员：

```sql
UPDATE users SET role='admin' WHERE username='你的用户名';
```

或使用自带工具一步到位：

```bash
php _restore/tools/reset_password.php 你的用户名 新密码 admin
```

打开 `http://127.0.0.1:8080/index.html` 即可使用。

## 目录结构

```
.
├── index.html            后台管理端（登录、问卷设计、我的问卷、结果查看）
├── fill.html             公开填写页  →  fill.html?id=问卷ID
├── db.php                会话初始化 + 数据库连接（不含任何口令）
├── config.example.php    配置模板
├── api/                  REST 风格接口，统一返回 JSON
│   ├── login.php  register.php  logout.php  check_login.php
│   ├── create_survey.php  update_survey.php  delete_survey.php
│   ├── get_survey.php  get_surveys.php
│   └── submit_response.php  get_responses.php  export_results.php
├── _restore/
│   ├── schema.sql                      建表脚本（6 张表）
│   └── tools/
│       ├── reset_password.php          重置用户密码（命令行）
│       └── backup_database.ps1         数据库定时备份（Windows）
├── tests/
│   └── acceptance_test.ps1             端到端验收测试（29 项断言，可对线上环境运行）
├── docs/
│   ├── DEPLOY.md                       部署指南（含 1Panel / 轻量服务器实践）
│   └── OPERATIONS.md                   运维手册（排错、备份、数据结构约定）
└── LICENSE
```

## 数据库设计

```
users (id, username, password, role, email, created_at, status)
  └─< surveys (id, title, description, user_id, status, created_at, updated_at)
        └─< questions (id, survey_id, title, type, required, sort_order)
        │     └─< options (id, question_id, text, sort_order)      仅单选/多选
        └─< responses (id, survey_id, submit_time, ip_address)
              └─< answers (id, response_id, question_id, answer_text)
```

- `questions.type`：`radio` 单选 / `checkbox` 多选 / `text` 文本（表中另预留 `matrix`、`scale`，前端未实现）
- `surveys.status`：`draft` 草稿 / `published` 已发布 / `closed` 已关闭
- 表引擎为 **MyISAM**：不支持外键与级联删除，因此删除问卷时由 `api/delete_survey.php` 在事务内按依赖顺序显式清理，避免产生孤儿数据

## 界面截图

<!-- 建议在这里放 2–3 张截图，效果会好很多：
     1) 后台问卷设计界面  2) 我的问卷列表  3) 手机端填写页
     放到 docs/screenshots/ 后用 Markdown 引用，例如：
     ![问卷设计](docs/screenshots/design.png) -->

## 测试

仓库附带一套端到端验收测试 `tests/acceptance_test.ps1`，覆盖 **29 项断言**：
页面可达性、注册与会话保持、草稿隔离与越权拦截、发布与选项渲染、必答校验、
拒收非本问卷字段、答卷写入与时间字段、CSV 导出与 BOM、未登录越权拦截、
删除后的级联清理与测试数据清理。

它会自行创建测试账号和问卷，跑完自动清理，因此**可以对线上环境直接运行**：

```powershell
# 对线上环境跑（从你的 Windows 机器执行即可，服务器上不需要装 PowerShell）
powershell -ExecutionPolicy Bypass -File tests\acceptance_test.ps1 -BaseUrl http://你的域名

# 本机跑（额外传入 MySQL 客户端路径，可多跑 2 项数据库级断言）
powershell -ExecutionPolicy Bypass -File tests\acceptance_test.ps1 -BaseUrl http://127.0.0.1:8080 -MysqlExe "C:\path\to\mysql.exe"
```

> 不传 `-MysqlExe` 时会跳过"孤儿数据检查"和"测试数据清理"两项，脚本会明确报告 SKIP。

另有一套 **JS 冒烟测试** `tests/js_smoke_test.js`（Node.js）：把页面脚本放进最小 DOM 桩环境执行，
并静态检查 `getElementById()` 引用的元素是否存在。它专门拦截「引用了未定义的函数 / 不存在的元素」
这类**只有浏览器才会暴露、接口测试永远测不出**的前端错误：

```bash
node tests/js_smoke_test.js
```

## 部署

完整的服务器部署步骤见 **[docs/DEPLOY.md](docs/DEPLOY.md)**（含国内服务器备案注意事项、
1Panel 建站、数据库导入、HTTPS 与上线检查清单）。

## 已知限制与后续计划

**当前限制**

- 表引擎为 MyISAM，无外键约束、无事务级回滚保障，写入密集场景不适合（数据量小则无影响）
- 接口未做 CSRF Token 校验，依赖同源部署
- 矩阵题（matrix）、量表题（scale）仅在数据库层预留，前端与接口未实现
- 结果导出仅支持 CSV，暂无图表统计
- 无邮件通知、无答卷配额限制

**后续计划**

- [ ] 迁移到 InnoDB，补上外键与真实级联
- [ ] 增加 CSRF Token 与接口频率限制
- [ ] 结果统计图表（柱状图 / 饼图）
- [ ] 问卷模板与复制功能
- [ ] 导出 Excel（.xlsx）

## 项目背景

这个项目最初是为了快速收集问卷而手写的：本地用 phpstudy 开发，之后部署到云服务器（1Panel 面板）对外运行。

后来服务器到期释放，我借此机会对项目做了一次系统性复盘：重建运行环境、逐行审计接口代码、
修复了一批长期潜伏的缺陷（其中最严重的一个是整段代码被误粘贴两遍导致接口返回两段 JSON，
以及数据库列名与代码不一致导致答卷根本无法提交），并把配置与密钥分离、补齐备份机制、
建立端到端的自动化验证。现在这个仓库就是这次加固后的版本。

## 许可

[MIT](LICENSE)
