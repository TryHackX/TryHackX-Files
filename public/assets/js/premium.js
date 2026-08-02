(function () {
    'use strict';

    const applyButton = document.getElementById('promoApply');
    const input = document.getElementById('promoInput');
    const status = document.getElementById('promoStatus');
    const bootstrap = document.getElementById('premiumBootstrap');
    if (!applyButton || !input || !status || !bootstrap) return;

    let messages = {};
    try {
        messages = JSON.parse(bootstrap.dataset.messages || '{}');
    } catch (_error) {
        messages = {};
    }

    function updateCheckoutLinks(code, scopePlanId = 0) {
        document.querySelectorAll('form.plan-checkout-form').forEach((form) => {
            const base = form.action.replace(/&code=[^&]*/, '');
            const applies = !scopePlanId || Number(form.dataset.planId) === Number(scopePlanId);
            form.action = code && applies ? `${base}&code=${encodeURIComponent(code)}` : base;
        });
    }

    async function checkPromoCode() {
        const code = input.value.trim();
        if (!code) {
            updateCheckoutLinks('');
            status.textContent = '';
            return;
        }

        try {
            const response = await window.FHApi.get('promo_check', { code });
            if (response && response.success && response.valid) {
                updateCheckoutLinks(response.code, response.scope === 'plan' ? response.planId : 0);
                status.style.color = 'var(--success)';
                const template = response.scope === 'plan'
                    ? (messages.validPlan || messages.valid || '')
                    : (messages.valid || '');
                status.textContent = String(template)
                    .replace(':pct', response.percentOff)
                    .replace(':plan', response.planName || '');
                return;
            }

            updateCheckoutLinks('');
            status.style.color = 'var(--danger)';
            status.textContent = messages.invalid || '';
        } catch (_error) {
            status.style.color = 'var(--danger)';
            status.textContent = messages.connectionError || '';
        }
    }

    applyButton.addEventListener('click', checkPromoCode);
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            checkPromoCode();
        }
    });
}());
