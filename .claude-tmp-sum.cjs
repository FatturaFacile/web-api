const t=require("fs").readFileSync(process.argv[2],"utf8");const i=t.indexOf('{"tool"');
if(i<0){console.log(t.slice(0,1500));process.exit();}
const j=JSON.parse(t.slice(i));
console.log(j.result,"tests",j.tests,"passed",j.passed,"failed",j.failed||0,"errors",j.errors||0);
for(const f of j.failures||j.error_details||[])console.log("-",String(f.test).replace(/.*__pest_evaluable_/,"").slice(0,90),"|",String(f.message).replace(/\n/g," ").slice(0,400));
