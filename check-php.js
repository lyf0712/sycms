// PHP 语法验证脚本:使用 php-parser 检查所有 PHP 文件
// 用法: node check-php.js [目录]  (默认当前目录)
const fs = require('fs');
const path = require('path');
const Engine = require('php-parser');

const parser = new Engine({
  parser: { extractDoc: true, php7: true },
  ast: { withPositions: true },
});

const root = process.argv[2] ? path.resolve(process.argv[2]) : process.cwd();
const files = [];
function walk(dir) {
  for (const f of fs.readdirSync(dir)) {
    if (f === 'node_modules' || f.startsWith('.')) continue;
    const p = path.join(dir, f);
    const st = fs.statSync(p);
    if (st.isDirectory()) walk(p);
    else if (f.endsWith('.php')) files.push(p);
  }
}
walk(root);

let errors = 0;
for (const file of files) {
  const code = fs.readFileSync(file, 'utf8');
  try {
    parser.parseCode(code);
    console.log('  [OK]  ' + path.relative(root, file));
  } catch (e) {
    errors++;
    console.log('  [ERR] ' + path.relative(root, file) + '  ->  ' + e.message);
  }
}
console.log('\n共检查 ' + files.length + ' 个 PHP 文件,' + (errors === 0 ? '全部通过 ✓' : errors + ' 个错误 ✗'));
process.exit(errors === 0 ? 0 : 1);
