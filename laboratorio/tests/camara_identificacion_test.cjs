const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../js/identificacion_muestra.js'),'utf8');
const qrcode=require('../../cengicursos/js/qrcode-generator.js'),jsQR=require('../../cengicursos/js/jsQR.js');
const qr=qrcode(0,'M');qr.addData('S-001-11-26');qr.make();
const width=(qr.getModuleCount()+8)*4,data=new Uint8ClampedArray(width*width*4);
for(let y=0;y<width;y++)for(let x=0;x<width;x++){const r=Math.floor(y/4)-4,c=Math.floor(x/4)-4,dark=r>=0&&c>=0&&r<qr.getModuleCount()&&c<qr.getModuleCount()&&qr.isDark(r,c);const i=(y*width+x)*4;data[i]=data[i+1]=data[i+2]=dark?0:255;data[i+3]=255;}
function setup(options={}){
 const listeners={},open={focus(){},hasAttribute(){return !!options.batch;},addEventListener(k,f){listeners['open:'+k]=f;}},close={focus(){},addEventListener(k,f){listeners['close:'+k]=f;}};
 const content={textContent:''},status={classList:{toggle(){}},querySelector(){return content;}};
 const video={readyState:2,videoWidth:width,videoHeight:width,srcObject:null,async play(){}};
 const canvas={getContext(){return {drawImage(){},getImageData(){return {data,width,height:width};}};}};
 const modal={hidden:true,querySelector(s){return {'[data-scanner-video]':video,'[data-scanner-canvas]':canvas,'[data-scanner-status]':status,'[data-close-scanner]':close}[s]||null;},querySelectorAll(){return [close];},addEventListener(k,f){listeners['modal:'+k]=f;}};
 const value={value:''},form={submitted:0,querySelector(){return value;},submit(){this.submitted++;}};
 const page={events:[],querySelector(){return null;},querySelectorAll(s){return s==='[data-open-scanner]'?[open]:[];},dispatchEvent(e){this.events.push(e);}};
 const document={querySelector(s){return {'[data-ident-page]':page,'[data-scanner-modal]':modal,'[data-scanner-submit]':form}[s]||null;},addEventListener(k,f){listeners['document:'+k]=f;}};
 let stopped=0,calls=[];
 const stream={getTracks(){return [{stop(){stopped++;}}];}};
 const window={isSecureContext:options.secure!==false,jsQR:options.noDecoder?undefined:jsQR,addEventListener(k,f){listeners['window:'+k]=f;}};
 if(options.Detector)window.BarcodeDetector=options.Detector;
 const context={window,document,navigator:{mediaDevices:{async getUserMedia(c){calls.push(c);if(options.acquire)return options.acquire(c,stream);if(options.error)throw {name:options.error};return stream;}}},HTMLMediaElement:{HAVE_CURRENT_DATA:2},requestAnimationFrame(){return 1;},cancelAnimationFrame(){},CustomEvent:class{constructor(type,init){this.type=type;this.detail=init.detail;}}};
 vm.createContext(context);vm.runInContext(source,context);
 return {listeners,open,close,modal,video,page,form,value,content,calls,get stopped(){return stopped;},start:()=>listeners['open:click']({currentTarget:open})};
}
async function flush(){for(let i=0;i<12;i++)await Promise.resolve();}
(async()=>{
 const fallback=setup();await fallback.start();await flush();
 assert.equal(fallback.calls.length,1,'Solicita cámara aunque no exista BarcodeDetector');assert.equal(fallback.calls[0].audio,false);assert.equal(fallback.calls[0].video.facingMode.ideal,'environment');
 assert.equal(fallback.value.value,'S-001-11-26','Lectura QR real con la biblioteca local');assert.equal(fallback.form.submitted,1);assert.equal(fallback.stopped,1);assert.equal(fallback.video.srcObject,null);
 const batch=setup({batch:true});await batch.start();await flush();assert.equal(batch.form.submitted,0);assert.equal(batch.page.events[0].detail,'S-001-11-26');
 class Detector {static async getSupportedFormats(){return ['code_128'];}constructor(opts){assert.deepEqual(Array.from(opts.formats),['code_128']);}async detect(){return [{rawValue:'S-BARRAS'}];}}
 const native=setup({Detector});await native.start();await flush();assert.equal(native.value.value,'S-BARRAS');
 class Broken {constructor(){throw Error('unsupported');}}
 const broken=setup({Detector:Broken});await broken.start();await flush();assert.equal(broken.value.value,'S-001-11-26');
 for(const [error,text]of [['NotAllowedError','permiso'],['NotFoundError','No se encontró'],['NotReadableError','otras aplicaciones']]){const s=setup({error});await s.start();assert.ok(s.content.textContent.includes(text));assert.equal(s.form.submitted,0);}
 const insecure=setup({secure:false});await insecure.start();assert.equal(insecure.calls.length,0);assert.ok(insecure.content.textContent.includes('HTTPS'));
 let resolve;const pending=setup({acquire:(_,s)=>new Promise(r=>{resolve=()=>r(s);})});const start=pending.start();pending.listeners['close:click']();resolve();await start;assert.equal(pending.stopped,1,'Cerrar durante permiso pendiente apaga la cámara cuando responde');assert.equal(pending.form.submitted,0);
 let count=0;const retry=setup({acquire:async(_,s)=>{if(!count++)throw {name:'OverconstrainedError'};return s;}});await retry.start();await flush();assert.equal(retry.calls.length,2);assert.equal(retry.calls[1].video,true);
 const missing=setup({noDecoder:true});await missing.start();assert.equal(missing.stopped,1);assert.ok(missing.content.textContent.includes('lector de códigos'));
 console.log('OK: QR real sin BarcodeDetector, cámara nativa, lector por lote, permisos, errores, cierre y compatibilidad.');
})().catch(e=>{console.error(e);process.exitCode=1;});
