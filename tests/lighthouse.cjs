const fs = require('node:fs');
const path = require('node:path');
(async () => {
  const { default: lighthouse } = await import('lighthouse');
  const { launch } = await import('chrome-launcher');
  const profile = path.join(__dirname, '../.cache/lighthouse-profile');
  fs.mkdirSync(profile, {recursive:true});
  const chrome = await launch({chromePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', userDataDir:profile, chromeFlags: ['--headless=new', '--disable-extensions']});
  try {
    const routes = {home: '/', produto: '/produto.php?slug=peca-de-cosplay-01'};
    const summary = {};
    for (const [name, route] of Object.entries(routes)) {
      const result = await lighthouse((process.env.QA_URL || 'http://localhost:8000') + route, {port: chrome.port, output: ['json','html'], logLevel: 'error', onlyCategories: ['performance','accessibility','best-practices','seo']});
      fs.mkdirSync(path.join(__dirname, '../docs/lighthouse'), {recursive:true});
      fs.writeFileSync(path.join(__dirname, '../docs/lighthouse', name + '.json'), result.report[0]);
      fs.writeFileSync(path.join(__dirname, '../docs/lighthouse', name + '.html'), result.report[1]);
      summary[name] = Object.fromEntries(Object.entries(result.lhr.categories).map(([key, value]) => [key, Math.round(value.score * 100)]));
      summary[name].url = result.lhr.finalDisplayedUrl;
      summary[name].at = result.lhr.fetchTime;
      summary[name].issues = Object.values(result.lhr.audits).filter(a => a.score !== null && a.score < 1 && ['binary','numeric'].includes(a.scoreDisplayMode)).map(a => ({id:a.id,title:a.title,display:a.displayValue}));
    }
    fs.writeFileSync(path.join(__dirname,'../docs/lighthouse/summary.json'), JSON.stringify(summary,null,2));
    console.log(JSON.stringify(summary,null,2));
  } finally { await chrome.kill(); }
})().catch(e => {console.error(e);process.exit(1);});
