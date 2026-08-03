<?php
/**
 * 模板变量渲染引擎
 *
 * 核心机制:
 *   1. 读取 templates/ 目录下的前端文件(HTML/CSS/JS)
 *   2. 扫描文件内容中的 {{ 变量名 }} 占位符
 *   3. 用数据库中变量表的值替换占位符
 *   4. 输出渲染后的完整页面
 *
 * 用法示例(index.php):
 *   $html = Template::renderFile('index.html');
 *   echo $html;
 */

class Template
{
    /** 占位符正则:匹配 {{ key }},key 允许字母数字下划线 */
    public const PLACEHOLDER_RE = '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/';

    /**
     * 简易语法高亮(后端实现,零依赖)
     * 支持 html / css / js,其它语言会尝试用 html 兜底。
     * 输出已经 HTML 转义 + 包裹 <span class="..."> 标签,直接 echo 即可。
     */
    public static function highlight(string $code, ?string $lang = null): string
    {
        $ext = strtolower($lang ?? '');
        $h = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        // 1) 占位符 {{ key }} —— 优先高亮(其它规则不破坏它)
        $h = preg_replace(
            '/(\{\{\s*[a-zA-Z0-9_]+\s*\}\})/',
            '<span class="hl">$1</span>',
            $h
        );
        if ($ext === 'css') {
            // 注释
            $h = preg_replace('/(\/\*[\s\S]*?\*\/)/', '<span class="cmt">$1</span>', $h);
            // 选择器(类/ID/标签,后跟 { 或 , 或 空格+{ 或 媒体查询等)
            $h = preg_replace('/(^|[\s,>+~])([.#]?[a-zA-Z][\w-]*)(?=\s*[\{,])/m', '$1<span class="sel">$2</span>', $h);
            // 属性
            $h = preg_replace('/(\s)([a-zA-Z-]+)(\s*:\s*)/', '$1<span class="prop">$2</span>$3', $h);
            // 数字 + 单位
            $h = preg_replace('/(?<![\w-])(\d+(?:\.\d+)?)(px|em|rem|%|deg|s|ms|vh|vw|vmin|vmax)?/', '<span class="num">$1$2</span>', $h);
        } elseif ($ext === 'js') {
            // 注释
            $h = preg_replace('/(\/\/[^\n]*)/', '<span class="cmt">$1</span>', $h);
            $h = preg_replace('/(\/\*[\s\S]*?\*\/)/', '<span class="cmt">$1</span>', $h);
            // 字符串
            $h = preg_replace('/(&quot;[^&\n]*&quot;|&#039;[^&\n]*&#039;|`[^`]*`)/', '<span class="str">$1</span>', $h);
            // 关键字
            $h = preg_replace(
                '/\b(const|let|var|function|return|if|else|for|while|do|switch|case|break|continue|new|class|extends|this|super|import|export|from|as|async|await|try|catch|finally|throw|typeof|instanceof|in|of|new|delete|void|null|true|false|undefined|async|yield)\b/',
                '<span class="kw">$1</span>',
                $h
            );
            // 数字
            $h = preg_replace('/(?<![\w$])(\d+(?:\.\d+)?)(?![\w$])/', '<span class="num">$1</span>', $h);
        } else {
            // html (含 .htm / 默认)
            // 注释
            $h = preg_replace('/(&lt;!--[\s\S]*?--&gt;)/', '<span class="cmt">$1</span>', $h);
            // 标签名
            $h = preg_replace('/(&lt;\/?)([a-zA-Z][a-zA-Z0-9-]*)/', '$1<span class="tag">$2</span>', $h);
            // 属性名
            $h = preg_replace('/(\s)([a-zA-Z-][\w-]*)(=)/', '$1<span class="attr">$2</span>$3', $h);
            // 属性值
            $h = preg_replace('/(=)("[^"]*"|&#039;[^&]*&#039;)/', '$1<span class="val">$2</span>', $h);
        }
        return $h;
    }

    /**
     * 渲染字符串:替换 {{ key }} 为数据库变量值
     * 未定义的变量保留原样并加注释,便于排查。
     * 支持注入特殊变量:$extra['csrf_token'] 等(前端表单安全所需)。
     */
    public static function renderString(string $content, array $extra = []): string
    {
        $vars = self::allVariables();

        // 第 1 步:HTML 静态资源路径自动补前缀
        // 模板里写 <link href="style.css"> / <script src="main.js"> / <img src="logo.png">
        // 渲染后自动变为 templates/style.css 等 —— 文件真实存在于站点根目录,
        // 任何服务器(nginx/Apache/宝塔)默认即可直接服务,无需 rewrite/伪静态配置。
        $content = self::fixAssetPaths($content);

        // 第 1.5 步:表单自动增强 —— 让"完全不懂代码"的用户上传模板即可提交表单
        // 自动给 <form> 补 method="post" action="index.php",并注入 _csrf / page_name 隐藏字段。
        $content = self::enhanceForms($content);

        // 第 2 步:替换变量占位符
        $result = preg_replace_callback(self::PLACEHOLDER_RE, function ($m) use ($vars, $extra) {
            $key = $m[1];
            if (array_key_exists($key, $extra)) {
                return (string) $extra[$key];
            }
            if (isset($vars[$key])) {
                return (string) $vars[$key];
            }
            // 变量未定义:保留占位符并附注释(浏览器不显示注释)
            return '<!-- 未定义变量:' . $key . ' -->{{ ' . $key . ' }}';
        }, $content);
        return $result === null ? $content : $result;
    }

    /**
     * 表单自动增强 —— 上传模板零配置即可收集访客数据
     * 1. <form> 标签缺 method 补 "post",缺 action 补 "index.php"
     * 2. 表单内没有 _csrf 时自动注入隐藏字段(_csrf + page_name)
     * 已显式声明 method/action/_csrf 的表单原样保留,不重复处理。
     */
    private static function enhanceForms(string $html): string
    {
        // 1. 补 <form> 属性
        $html = preg_replace_callback('/<form\b([^>]*)>/i', function ($m) {
            $attrs = $m[1];
            if (!preg_match('/\bmethod\s*=/i', $attrs)) {
                $attrs .= ' method="post"';
            }
            if (!preg_match('/\baction\s*=/i', $attrs)) {
                $attrs .= ' action="index.php"';
            }
            return '<form' . $attrs . '>';
        }, $html);

        // 2. 注入隐藏字段(仅当表单内还没有 _csrf)
        $html = preg_replace_callback('/(<form\b[^>]*>)(.*?)(<\/form>)/is', function ($m) {
            $inner = $m[2];
            if (preg_match('/name\s*=\s*["\']?_csrf/i', $inner)) {
                return $m[0]; // 已有 _csrf,不重复注入
            }
            $hidden = "\n    <input type=\"hidden\" name=\"_csrf\" value=\"{{ csrf_token }}\">"
                . "\n    <input type=\"hidden\" name=\"page_name\" value=\"{{ site_name }}\">\n";
            return $m[1] . $inner . $hidden . $m[3];
        }, $html);

        return $html;
    }

    /**
     * 自动修正 HTML 中相对静态资源路径
     * 匹配 <link href> / <script src> / <img src> 里的相对路径,补 templates/ 前缀。
     * 跳过:绝对 URL(http/https)、根路径(/)、协议无关(//)、data:、锚点(#)、
     *     已带 templates/ 前缀、{{ }} 占位符、../ 上跳路径。
     */
    private static function fixAssetPaths(string $html): string
    {
        // 静态资源扩展名(与上传/保存白名单一致,不含 html 避免误改页面链接)
        $staticExt = '(?:css|js|mjs|png|jpe?g|gif|webp|svg|ico|txt|json|woff2?|ttf)';
        $pattern = '/(<(?:link|script|img)\s+[^>]*?(?:href|src)=["\'])([^"\']+?)(["\'])/i';

        return preg_replace_callback($pattern, function ($m) use ($staticExt) {
            $url = $m[2];
            // 跳过:绝对地址 / 协议无关 / 根路径 / data / 锚点 / js 协议 / 占位符 / 上跳 / 已带前缀
            if (preg_match('~^(?:https?:)?//|^/|^data:|^#|^javascript:|^\.\./|^\{\{|\btemplates/~i', $url)) {
                return $m[0];
            }
            // 仅当扩展名是静态资源时才补前缀(否则是页面链接,保持原样)
            if (!preg_match('/\.' . $staticExt . '(?:[?#].*)?$/i', $url)) {
                return $m[0];
            }
            return $m[1] . 'templates/' . $url . $m[3];
        }, $html);
    }

    /**
     * 渲染指定模板文件(自动替换全部变量)
     * @param string $file 相对于 templates/ 的文件名,如 'index.html'
     */
    public static function renderFile(string $file, array $extra = []): string
    {
        $path = self::resolvePath($file);
        if (!$path) {
            return '<!-- 模板文件不存在:' . e($file) . ' -->';
        }
        $content = file_get_contents($path);
        return self::renderString($content, $extra);
    }

    /**
     * 取出所有变量 key => value 映射
     * 附带一个静态缓存,避免同一请求多次查询。
     */
    public static function allVariables(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        try {
            $rows = Database::rows("SELECT `var_key`, `var_value` FROM `" . DB_PREFIX . "variables`");
            foreach ($rows as $r) {
                $cache[$r['var_key']] = $r['var_value'];
            }
        } catch (Throwable $e) {
            // 未安装或数据库未就绪时返回空
            $cache = [];
        }
        return $cache;
    }

    /**
     * 扫描某个模板文件,列出其中用到的变量 key 及出现次数
     * (对应后台"模板文件"页的变量映射面板)
     */
    public static function scanFile(string $file): array
    {
        $path = self::resolvePath($file);
        $found = [];
        if (!$path) {
            return $found;
        }
        $content = file_get_contents($path);
        preg_match_all(self::PLACEHOLDER_RE, $content, $matches);
        foreach ($matches[1] as $key) {
            $found[$key] = ($found[$key] ?? 0) + 1;
        }
        return $found;
    }

    /**
     * 扫描 templates/ 下所有文件,返回 [文件 => [key => 次数]]
     */
    public static function scanAll(): array
    {
        $result = [];
        $dir = TEMPLATE_DIR;
        if (!is_dir($dir)) {
            return $result;
        }
        $files = glob($dir . '/*.*');
        if ($files === false) {
            return $result;
        }
        foreach ($files as $f) {
            if (is_file($f)) {
                $name = basename($f);
                $result[$name] = self::scanFile($name);
            }
        }
        return $result;
    }

    /** 校验文件名安全性并解析为绝对路径 */
    private static function resolvePath(string $file): ?string
    {
        // 只允许文件名(禁止路径穿越)
        if ($file === '' || strpos($file, '/') !== false || strpos($file, '\\') !== false || strpos($file, '..') !== false) {
            return null;
        }
        $path = TEMPLATE_DIR . DIRECTORY_SEPARATOR . $file;
        return is_file($path) ? $path : null;
    }
}
