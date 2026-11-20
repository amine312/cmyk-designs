'use strict';
const toggle=document.getElementById('menu-toggle');
const sidebar=document.getElementById('sidebar');
if(toggle&&sidebar){
  const close=()=>{sidebar.classList.remove('open');toggle.setAttribute('aria-expanded','false');toggle.setAttribute('aria-label','Ouvrir la navigation');};
  toggle.addEventListener('click',()=>{const open=sidebar.classList.toggle('open');toggle.setAttribute('aria-expanded',String(open));toggle.setAttribute('aria-label',open?'Fermer la navigation':'Ouvrir la navigation');});
  document.addEventListener('click',event=>{if(!sidebar.contains(event.target)&&!toggle.contains(event.target))close();});
  document.addEventListener('keydown',event=>{if(event.key==='Escape'){close();toggle.focus();}});
}
document.querySelectorAll('form[data-confirm]').forEach(form=>form.addEventListener('submit',event=>{if(!window.confirm(form.dataset.confirm))event.preventDefault();}));
const form=document.getElementById('order-form');
if(form){
  const clientBox=document.getElementById('client-preview');
  if(clientBox){const clients=JSON.parse(clientBox.dataset.clients);const refreshClient=()=>{const c=clients[form.elements.client_id.value];document.getElementById('client-details').textContent=c?[c.company,c.contact,c.address,[c.phone,c.email].filter(Boolean).join(' · '),'ICE : '+(c.ice||'—')+' · IF : '+(c.tax_id||'—')].filter(Boolean).join('\n'):'Choisissez un client.';};form.elements.client_id.addEventListener('change',refreshClient);refreshClient();}
}

const paymentForm=document.getElementById('payment-form');
if(paymentForm){const setState=()=>{if(paymentForm.elements.method.value==='Chèque')paymentForm.elements.state.value='En attente';};paymentForm.elements.method.addEventListener('change',setState);setState();}

const lineEditor=document.getElementById('line-editor');
if(lineEditor){
  const list=lineEditor.querySelector('[data-line-list]'),errors=lineEditor.querySelector('#line-errors');
  const initialRow=list.querySelector('[data-line-row]').cloneNode(true);
  let nextIndex=list.children.length;
  const decimal=(value,precision,whole)=>{
    const s=value.replace(',','.');if(!new RegExp('^\\d{1,'+whole+'}(?:\\.\\d{1,'+precision+'})?$').test(s))return null;
    const parts=s.split('.');return BigInt(parts[0])*10n**BigInt(precision)+BigInt((parts[1]||'').padEnd(precision,'0'));
  };
  const cash=c=>new Intl.NumberFormat('fr-MA',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(c)/100)+' DH';
  const update=()=>{
    const sums={gross:0n,discount:0n,net:0n,tax:0n,'gross-total':0n};let valid=true;
    list.querySelectorAll('[data-line-row]').forEach((row,i)=>{
      row.querySelector('[data-line-number]').textContent=String(i+1);
      const val=k=>row.querySelector('[data-line-field="'+k+'"]').value;
      const q=decimal(val('quantity'),3,6),p=decimal(val('unit_price'),2,7),d=decimal(val('discount_rate'),2,3),t=decimal(val('tax_rate'),2,3);
      const ok=q!==null&&q>0n&&p!==null&&d!==null&&d<=10000n&&t!==null&&t<=10000n;
      row.querySelector('[data-remove-line]').disabled=list.children.length===1;
      if(!ok){valid=false;row.querySelector('[data-line-net]').textContent='—';row.querySelector('[data-line-total]').textContent='—';return;}
      const gross=(q*p+500n)/1000n,discount=(gross*d+5000n)/10000n,net=gross-discount,tax=(net*t+5000n)/10000n,total=net+tax;
      sums.gross+=gross;sums.discount+=discount;sums.net+=net;sums.tax+=tax;sums['gross-total']+=total;
      if(gross>999999999999n||sums.gross>999999999999n||sums['gross-total']>999999999999n)valid=false;
      row.querySelector('[data-line-net]').textContent=cash(net);row.querySelector('[data-line-total]').textContent=cash(total);
    });
    for(const [key,value]of Object.entries(sums))lineEditor.querySelector('[data-total-'+key+']').textContent=valid?cash(value):'—';
    errors.textContent=valid?'':'Vérifiez les quantités, les montants et les taux (0 à 100 %).';
    lineEditor.querySelector('[data-add-line]').disabled=list.children.length>=50;
  };
  lineEditor.addEventListener('input',update);
  lineEditor.addEventListener('click',event=>{
    if(event.target.closest('[data-add-line]')&&list.children.length<50){
      const row=initialRow.cloneNode(true);row.querySelectorAll('input,select').forEach(input=>{
        input.name=input.name.replace(/lines\[\d+\]/,'lines['+nextIndex+']');
        if(input.name.endsWith('[designation]'))input.value='';
        else if(input.name.endsWith('[quantity]'))input.value='1.000';
        else if(input.name.endsWith('[unit]'))input.value='u';
        else if(input.name.endsWith('[tax_rate]'))input.value=lineEditor.dataset.defaultTax;
        else input.value='0.00';
      });nextIndex++;list.appendChild(row);update();row.querySelector('input').focus();
    }
    if(event.target.closest('[data-remove-line]')&&list.children.length>1){event.target.closest('[data-line-row]').remove();update();}
  });
  update();
}

const draftEditor=document.querySelector('[data-draft-editor]');
if(draftEditor){
  const markDirty=()=>{document.querySelectorAll('[data-issue-draft]').forEach(form=>{form.querySelector('button').disabled=true;form.querySelector('[data-draft-dirty]').textContent='Enregistrez vos modifications avant émission.';});};
  draftEditor.addEventListener('input',markDirty);draftEditor.addEventListener('change',markDirty);
  draftEditor.addEventListener('click',event=>{if(event.target.closest('[data-add-line],[data-remove-line]'))markDirty();});
}

// Credit note estimates use the same cumulative cent allocation as PHP.
(() => {
  const form = document.getElementById('credit-form'); if (!form) return;
  const parseMilli = value => { const s = value.trim().replace(',', '.'); if (!/^\d{1,6}(?:\.\d{1,3})?$/.test(s)) throw Error('Quantité invalide.'); const [a,b=''] = s.split('.'); return BigInt(a)*1000n+BigInt(b.padEnd(3,'0')); };
  const fmt = n => `${(n/100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g,' ')},${(n%100n).toString().padStart(2,'0')} DH`;
  const amount = (a,p,w) => (a*p+w/2n)/w;
  function update() {
    let net=0n,tax=0n; let valid=true;
    for (const row of form.querySelectorAll('[data-credit-line]')) {
      try {
        const whole=BigInt(row.dataset.whole),used=BigInt(row.dataset.used),qty=parseMilli(row.querySelector('[data-credit-qty]').value);
        if(qty>whole-used) throw Error('La quantité dépasse le reliquat disponible.');
        const n=amount(BigInt(row.dataset.net),used+qty,whole)-amount(BigInt(row.dataset.net),used,whole);
        const t=amount(BigInt(row.dataset.tax),used+qty,whole)-amount(BigInt(row.dataset.tax),used,whole);
        row.querySelector('[data-credit-net]').textContent=fmt(n);row.querySelector('[data-credit-tax]').textContent=fmt(t);net+=n;tax+=t;
      } catch(e) {valid=false; row.querySelector('[data-credit-net]').textContent='Quantité invalide';row.querySelector('[data-credit-tax]').textContent='';}
    }
    form.querySelector('[data-credit-total-net]').textContent=valid?fmt(net):'—';form.querySelector('[data-credit-total-tax]').textContent=valid?fmt(tax):'—';form.querySelector('[data-credit-total]').textContent=valid?fmt(net+tax):'—';
    form.querySelector('button[type="submit"],button:not([type])').disabled=!valid||net+tax<=0n;
    document.getElementById('credit-estimate-status').textContent=valid?'Montants à déduire de la facture d’origine.':'Vérifiez les quantités saisies.';
  }
  form.addEventListener('input',update); update();
})();

(() => {
 const form=document.getElementById('payroll-form');if(!form)return;
 const parse=v=>{if(!/^\d{1,9}(?:[.,]\d{1,2})?$/.test(v))throw Error('Montant invalide.');const [a,b='']=v.replace(',','.').split('.');return BigInt(a)*100n+BigInt(b.padEnd(2,'0'));};
 const fmt=v=>`${v/100n},${String(v%100n).padStart(2,'0')} DH`;
 function update(dirty=false){try{const get=k=>parse(form.elements[k].value);const gross=get('base_salary')+get('bonus'),deductions=get('social_deductions')+get('income_tax')+get('other_deductions');if(deductions>gross)throw Error('Les retenues dépassent le brut.');for(const [k,v]of Object.entries({gross,deductions,net:gross-deductions}))form.querySelector(`[data-pay-total="${k}"]`).textContent=fmt(v);form.querySelector('[data-pay-error]').textContent='';}catch(e){form.querySelector('[data-pay-error]').textContent=e.message;form.querySelectorAll('[data-pay-total]').forEach(e=>e.textContent='—');}if(dirty){document.querySelectorAll('[data-issue-payroll]').forEach(b=>{b.disabled=true;b.textContent='Enregistrez les changements avant émission';});}}
 form.addEventListener('input',()=>update(true));form.addEventListener('change',()=>update(true));update();
})();
