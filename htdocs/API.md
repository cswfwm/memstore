# MemStore 记忆存储服务 · 上传规范

版本 1.0.0 · 服务地址 `http://cswfwm.ccwu.cc/api/index.php`

本服务用于 AI 服务集中存放「记忆」文件：注册登录后，通过标准接口上传，文件按分类归档，可检索、下载、删除。

---

## 1. 基本约定

| 项目 | 约定 |
| --- | --- |
| 基础地址 | `http://cswfwm.ccwu.cc/api/index.php` |
| 路由参数 | 所有接口用查询参数 `?action=<动作>` 指定 |
| 请求编码 | `POST` 请求体为 JSON 时使用 `Content-Type: application/json`；上传文件使用 `multipart/form-data` |
| 响应格式 | 一律 UTF-8 JSON，`Content-Type: application/json; charset=utf-8` |
| 认证方式 | 注册/登录返回 `token`，后续请求加请求头 `X-Api-Token: <token>` |
| 客户端出口 | 该主机在应用前有 JS 校验页；程序调用时请使用搜索引擎爬虫 UA（见 `client.py`）以单请求直达，避免每日 hits 翻倍 |
| 独立域名 | 站点已绑定自有域名 `cswfwm.ccwu.cc`，NS 委派至 `ns1.uuk.pp.ua` / `ns2.uuk.pp.ua` |
| 三要素登录 | 用户名 + 密码 + 校验码 `keycode`（注册时由服务端生成并只返回一次） |
| 时间格式 | 服务端时间统一为 UTC，`YYYY-MM-DD HH:MM:SS` |
| 令牌有效期 | 默认 30 天，过期需重新登录 |

### 成功与错误响应

成功时 HTTP 状态码 2xx，响应体为业务数据。失败时状态码为 4xx/5xx，响应体固定为：

```json
{ "error": "错误代码", "message": "可直接展示给用户的中文说明" }
```

常见错误代码：`invalid_json` `invalid_username` `weak_password` `username_taken` `invalid_credentials` `unauthorized` `missing_file` `file_too_large` `not_found` `unknown_action`。

---

## 2. 注册

```
POST /api/index.php?action=register
Content-Type: application/json
```

```json
{
  "username": "my-ai-agent",
  "password": "至少8位密码",
  "service_name": "My AI Service",
  "I": "svc-aicode-001"
}
```

| 字段 | 必填 | 说明 |
| --- | --- | --- |
| `username` | 是 | 3–64 位，允许字母、数字、`_`、`.`、`-`，全局唯一 |
| `password` | 是 | 至少 8 位，服务端只存 BCrypt 哈希 |
| `service_name` | 是 | 调用方名称，最长 120 字，便于区分不同 AI 服务 |
| `I` | 是 | 调用方标识，1–64 位字母数字或 `_` `:` `.` `-`，随账号保存并在登录响应中回显 |

成功 `201`：

```json
{
  "user": { "id": 1, "username": "my-ai-agent", "service_name": "My AI Service", "I": "svc-aicode-001" },
  "keycode": "7f4fad0e40dbb5188d25abb97fa03023",
  "token": "64位十六进制字符串",
  "token_expires_at": "2026-12-08 15:00:00"
}
```

**`keycode` 是 128 位校验码（32 位十六进制，等价 128 bit），只在注册响应中返回这一次**，服务端仅存其 SHA-256 哈希，无法找回。调用方必须立即持久化保存，登录时必填。丢失校验码的账号无法登录，只能重新注册其他用户名。

注册时同时签发令牌，可直接上传。

## 3. 登录

```
POST /api/index.php?action=login
Content-Type: application/json
```

```json
{ "username": "my-ai-agent", "password": "至少8位密码", "keycode": "7f4fad0e40dbb5188d25abb97fa03023" }
```

| 字段 | 必填 | 说明 |
| --- | --- | --- |
| `username` | 是 | 注册时的用户名 |
| `password` | 是 | 注册时的密码 |
| `keycode` | 是 | 注册时返回的 128 位校验码 |

成功 `200`，返回同注册结构（`keycode` 字段不再返回，token 为新签发）。

错误：缺少校验码返回 `422 missing_keycode`；校验码错误返回 `401 invalid_keycode`；用户名或密码错误返回 `401 invalid_credentials`。

每次登录签发新令牌，旧令牌仍然有效直至过期。

## 4. 上传记忆

```
POST /api/index.php?action=upload
X-Api-Token: <token>
Content-Type: multipart/form-data
```

| 字段 | 必填 | 说明 |
| --- | --- | --- |
| `file` | 二选一 | 文件本体。单文件上限 20 MB（受主机 `upload_max_filesize` 限制） |
| `source` | 否 | `file`（默认）或 `text` |
| `content` | `source=text` 时必填 | 文本内容，服务端保存为文本文件 |
| `filename` | `source=text` 时可选 | 建议提供的文件名，默认 `note.txt` |
| `title` | 否 | 标题，最长 200 字；缺省用文件名 |
| `category` | 否 | 分类，缺省 `general`。允许字母数字、`-`、`_`，最长 32，自动转小写 |
| `tags` | 否 | 逗号分隔标签串，最长 255 字，仅作检索用途记录 |

示例（上传文件）：

```bash
curl -X POST 'http://cswfwm.ccwu.cc/api/index.php?action=upload' \
  -H "X-Api-Token: $TOKEN" \
  -F 'file=@session-notes.md' \
  -F 'title=2026-11-08 会话记忆' \
  -F 'category=session' \
  -F 'tags=agent,summary,zh'
```

示例（直接上传文本，无需本地文件）：

```bash
curl -X POST 'http://cswfwm.ccwu.cc/api/index.php?action=upload' \
  -H "X-Api-Token: $TOKEN" \
  -F 'source=text' \
  -F 'content=用户偏好中文回复，习惯用 JavaScript。' \
  -F 'filename=preference.txt' \
  -F 'category=preference'
```

成功 `201`：

```json
{
  "memory": {
    "uuid": "3f1c...-...",
    "title": "2026-11-08 会话记忆",
    "category": "session",
    "tags": "agent,summary,zh",
    "mime_type": "text/markdown",
    "size_bytes": 2048,
    "sha256": "…",
    "created_at": "2026-11-08 15:00:00",
    "download_url": "http://cswfwm.ccwu.cc/api/index.php?action=file&uuid=3f1c..."
  }
}
```

### 存储布局

服务端按 `storage/<年>/<月>/<分类>/<uuid>.<扩展名>` 归档，原始文件名、MIME、SHA-256、标签等元数据存 MySQL。`storage/` 目录禁止 Web 直接访问，只能通过 `action=file` 下载。

### 内容类型与扩展名

扩展名取原始文件名，非法时按 MIME 映射（`text/plain→txt`、`text/markdown→md`、`application/json→json`、`image/png→png` 等），无法判断则用 `bin`。下载响应带 `X-Content-Type-Options: nosniff`，并以附件形式返回，避免被浏览器当作页面执行。

## 5. 列出记忆

```
GET /api/index.php?action=list&category=session&limit=20&offset=0
X-Api-Token: <token>
```

| 参数 | 说明 |
| --- | --- |
| `category` | 可选，按分类过滤 |
| `limit` | 每页条数，1–100，默认 20 |
| `offset` | 偏移量，默认 0 |

返回仅包含当前令牌所属用户的记录，按上传时间倒序。

```json
{
  "total_returned": 1,
  "limit": 20,
  "offset": 0,
  "items": [
    {
      "uuid": "3f1c...",
      "title": "2026-11-08 会话记忆",
      "category": "session",
      "tags": "agent,summary,zh",
      "mime_type": "text/markdown",
      "size_bytes": 2048,
      "orig_name": "session-notes.md",
      "created_at": "2026-11-08 15:00:00",
      "download_url": "…"
    }
  ]
}
```

## 6. 下载记忆

```
GET /api/index.php?action=file&uuid=<uuid>
X-Api-Token: <token>
```

返回文件二进制流，响应头 `Content-Disposition: attachment`。仅能下载自己上传的文件。

## 7. 删除记忆

```
POST /api/index.php?action=delete
X-Api-Token: <token>
Content-Type: application/json
```

```json
{ "uuid": "3f1c..." }
```

成功 `200`：`{ "ok": true, "deleted": "3f1c..." }`。同时删除数据库记录与磁盘文件。

## 8. 查询当前身份

```
GET /api/index.php?action=me
X-Api-Token: <token>
```

## 9. 健康检查

```
GET /api/index.php?action=health
```

返回 `{"ok":true,"service":"memstore","version":"1.0.0","time":"…"}`，可用于探活。

---

## 10. 调用方最小实现流程

1. `health` 探活，确认服务可达。
2. 首次使用 `register`（需带 `I`），**同时保存返回的 `keycode` 与 `token`**；已有账号用 `login`（用户名 + 密码 + `keycode`）换取新 `token`。
3. 上传时按业务选择 `category`（例如 `session`、`preference`、`log`、`error`），并填写 `tags` 便于检索。
4. 检索用 `list`，需要正文时用 `file` 下载。
5. 令牌过期收到 `401 unauthorized` 时重新 `login`。

## 11. 安全与限制

- 密码仅存 BCrypt 哈希；令牌与 `keycode` 校验码仅存 SHA-256 哈希，明文只在签发时返回一次。
- 主机每日 50000 hits 限额，一次 API 调用消耗 1 hit；使用 `client.py` 的默认 UA 可保持单请求，勿用浏览器式 UA 触发挑战页。
- 页面样式已内联进 HTML，首页不再额外请求 CSS，减少 hits 消耗。
- 该主机的反向代理会剥离 `Authorization` 头，因此令牌统一用自定义头 `X-Api-Token` 传递；也支持 `?token=` 查询参数（便于浏览器内调试，但会出现在访问日志中）。
- 登录需用户名、密码、校验码三要素齐全，缺任一项均不能登录。
- 所有查询按 `user_id` 隔离，跨用户访问返回 `404 not_found`。
- 单文件上限 20 MB，主机磁盘配额 5 GB，单账号 1 个 MySQL 库。
- 上传目录位于 Web 根下但被 `.htaccess` 拒绝直接访问；文件名一律用服务端生成的 UUID，不使用用户提供的名称落盘。
- 目录穿越防护：下载与删除都会校验真实路径必须位于 `storage/` 内。
