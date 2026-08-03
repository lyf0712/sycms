# 速页CMS · 模板变量 CMS

一个**小型、快速、安全**的落地页内容管理系统。每个后台控制一个网站:前端文件放在 `templates/` 目录,代码中使用 `{{ 变量名 }}` 占位符,在后台修改变量值后,前端页面自动更新。

## 核心特性

- **自动安装引导** —— 全新部署后,访问站点任意地址自动进入安装向导:环境检测 → 数据库配置(测试连接)→ 管理员设置
- **变量管理** —— 管理变量(文本/多行文本/图片/链接/颜色/数字 6 种类型),自定义字段自动收集
- **模板文件** —— 前端文件放入 `templates/` 即挂载,文件中的 `{{ key }}` 自动替换
- **表单收集** —— 前端表单提交的访客线索自动入库(含 IP/设备/浏览器/来源),支持导出 CSV、状态流转、日期/状态筛选
- **系统设置** —— 修改管理员密码、生成隐蔽的后台访问入口
- **安全设计** —— PDO 预处理、密码哈希、CSRF 防护、XSS 转义、路径穿越防护、登录限速、文件扩展名白名单、CSV 公式注入防护

## 环境要求

| 项目 | 要求 |
|------|------|
| PHP | >= 7.4(推荐 8.x) |
| 扩展 | PDO、pdo_mysql、curl、GD(可选) |
| 数据库 | MySQL 5.7+ / MariaDB 10.3+ |
| 目录权限 | data/ 与 config.php 可写 |

## 安装步骤

### 方式一:网页安装向导(推荐)

1. 将本目录上传到服务器(如 `/www/wwwroot/你的站点/`)
2. 确保 `data/` 目录可写(权限 755 或 775)
3. 浏览器访问 `http://你的域名/` —— **未安装时自动跳转安装向导**(或直接访问 `http://你的域名/install.php`)
4. 按向导完成 3 步安装:
   - **环境检测**:自动检测 PHP 版本、MySQL、扩展、目录权限,不满足的项标红并提示所需版本
   - **数据库配置**:填写主机/端口/库名/用户名/密码/表前缀,点击"测试连接"
   - **管理员设置**:创建管理员账号密码
5. 安装完成后删除 `install.php` 与 `install-cli.php`(保留可重复安装,但生产环境建议删除)

### 方式二:命令行安装

```bash
php install-cli.php --host=127.0.0.1 --port=3306 --db=landing_page_cms --user=root --pass=你的密码 --prefix=lp_ --admin=admin --admin-pass=Admin@2026 --email=admin@example.com
```

## 目录结构

```
├── index.php            # 前端入口:渲染模板 + 变量替换 + 接收表单
├── install.php          # 网页安装向导(3 步)
├── install-cli.php      # 命令行安装(可选)
├── config.example.php   # 配置示例(安装后生成 config.php)
├── admin/               # 后台
│   ├── login.php        #   登录
│   ├── dashboard.php    #   工作台
│   ├── variables.php    #   变量管理
│   ├── templates.php    #   模板文件管理
│   ├── forms.php        #   表单收集
│   ├── settings.php     #   系统设置
│   └── partials/        #   公共头尾
├── lib/                 # 核心库
│   ├── bootstrap.php    #   引导
│   ├── database.php     #   数据库(PDO)
│   ├── auth.php         #   认证/CSRF
│   ├── template.php     #   模板变量渲染引擎
│   └── envcheck.php     #   环境检测
├── templates/           # 前端模板({{ key }} 占位符)
│   ├── index.html
│   ├── style.css
│   └── main.js
├── assets/              # 后台样式与脚本
└── data/                # 数据目录(需可写)
```

## 前端使用变量

在 `templates/` 下的任意 HTML/CSS/JS 文件中直接写占位符:

```html
<h1>{{ hero_title }}</h1>
<p>咨询电话:{{ contact_phone }}</p>
<a href="tel:{{ contact_phone }}">立即拨打</a>
```

后台"变量管理"中修改 `hero_title`、`contact_phone` 的值,前端页面自动更新。

## 本地预览

```bash
# 启动 PHP 内置服务器(需先安装完成)
php -S localhost:8080
# 访问 http://localhost:8080/
```

## 安全提示

1. 安装完成后请删除 `install.php`、`install-cli.php`
2. 在"系统设置"中生成随机的后台入口(如 `/manage-x7k9.php`),不要使用默认 `/admin`
3. 定期更换管理员密码
4. 生产环境建议配置 HTTPS 与服务器防火墙
