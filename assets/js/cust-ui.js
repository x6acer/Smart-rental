// Shared customer UI utilities (same as customer/js/cust-ui.js)
// This file mirrors the customer-local version and can be referenced from other paths.
(function () {
    'use strict';
    function ensureToastContainer() {
        let el = document.getElementById('cust-toast-container');
        if (!el) {
            el = document.createElement('div');
            el.id = 'cust-toast-container';
            el.style.position = 'fixed';
            el.style.right = '16px';
            el.style.top = '16px';
            el.style.zIndex = '9999';
            document.body.appendChild(el);
        }
        return el;
    }
    function showToast(type, message, timeout = 5000) {
        const container = ensureToastContainer();
        const toast = document.createElement('div');
        toast.className = 'cust-toast';
        toast.style.minWidth = '240px';
        toast.style.marginTop = '8px';
        toast.style.padding = '12px 14px';
        toast.style.borderRadius = '10px';
        toast.style.boxShadow = '0 6px 18px rgba(0,0,0,0.08)';
        toast.style.color = '#fff';
        toast.style.fontFamily = "Segoe UI, Open Sans, system-ui, -apple-system, 'Helvetica Neue', Arial";
        toast.style.fontSize = '13px';
        if (type === 'success') {
            toast.style.background = '#16a34a';
        } else if (type === 'warning') {
            toast.style.background = '#f59e0b';
        } else {
            toast.style.background = '#dc2626';
        }
        toast.textContent = message;
        container.appendChild(toast);
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 220ms ease-in-out';
            setTimeout(() => container.removeChild(toast), 260);
        }, timeout);
    }
    function spinnerElement(size = 16) {
        const span = document.createElement('span');
        span.className = 'cust-spinner';
        span.style.display = 'inline-block';
        span.style.width = size + 'px';
        span.style.height = size + 'px';
        span.style.border = Math.max(2, Math.floor(size / 8)) + 'px solid rgba(255,255,255,0.4)';
        span.style.borderTopColor = 'rgba(255,255,255,1)';
        span.style.borderRadius = '50%';
        span.style.animation = 'cust-spin 800ms linear infinite';
        return span;
    }
    const style = document.createElement('style');
    style.textContent = '\n@keyframes cust-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }\n';
    document.head.appendChild(style);
    function showButtonLoading(btn) {
        if (!btn) return;
        btn._origDisabled = btn.disabled;
        btn.disabled = true;
        btn._origContent = btn.innerHTML;
        const sp = spinnerElement(14);
        btn.innerHTML = '';
        btn.appendChild(sp);
        const text = document.createElement('span');
        text.style.marginLeft = '8px';
        text.style.fontWeight = '700';
        text.style.fontSize = '13px';
        text.textContent = 'Please wait';
        btn.appendChild(text);
    }
    function hideButtonLoading(btn) {
        if (!btn) return;
        btn.disabled = !!btn._origDisabled;
        if (btn._origContent !== undefined) btn.innerHTML = btn._origContent;
        btn._origContent = undefined;
        btn._origDisabled = undefined;
    }
    async function postForm(url, formData) {
        const opts = {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json'
            }
        };
        const res = await fetch(url, opts);
        if (!res.ok) {
            throw new Error('Network error: ' + res.status);
        }
        const contentType = res.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            throw new Error('Server returned non-JSON response. Status: ' + res.status + ', Type: ' + contentType);
        }
        try {
            return await res.json();
        } catch (e) {
            throw new Error('Failed to parse JSON response: ' + e.message);
        }
    }
    function initNewsletterForms() {
        const forms = Array.from(document.querySelectorAll('form'));
        forms.forEach(form => {
            const csrf = form.querySelector('input[name="csrf_token"]');
            const email = form.querySelector('input[type="email"]');
            if (!csrf || !email) return;
            if (form.dataset.custHandled) return;
            form.dataset.custHandled = '1';
            form.addEventListener('submit', async function (e) {
                e.preventDefault();
                const submitBtn = form.querySelector('button[type="submit"]') || form.querySelector('input[type="submit"]');
                showButtonLoading(submitBtn);
                const fd = new FormData();
                fd.append('email', email.value || '');
                fd.append('csrf_token', csrf.value || '');
                try {
                    const payload = await postForm((new URL(form.getAttribute('action') || 'subscribe-newsletter.php', window.location.href)).toString(), fd);
                    if (payload && payload.success) {
                        showToast('success', payload.message || 'Subscribed successfully');
                        email.value = '';
                    } else {
                        showToast('error', payload && payload.message ? payload.message : 'Subscription failed');
                    }
                } catch (err) {
                    showToast('error', err.message || 'Network failure');
                } finally {
                    hideButtonLoading(submitBtn);
                }
            });
        });
    }
    function initBrowseFilters() {
        const form = document.querySelector('form select[name="type"]')?.closest('form');
        if (!form) return;
        if (form.dataset.custFiltersAttached) return;
        form.dataset.custFiltersAttached = '1';
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            const submitBtn = form.querySelector('button[type="submit"]');
            showButtonLoading(submitBtn);
            const fd = new FormData(form);
            try {
                const url = new URL('filter-vehicles.php', window.location.href).toString();
                const payload = await postForm(url, fd);
                if (payload && payload.success) {
                    const container = document.querySelector('#Featured-v .grid.grid-cols-1') || document.querySelector('#Featured-v > div.grid');
                    if (container && payload.html) {
                        container.innerHTML = payload.html;
                        showToast('success', payload.message || 'Filters applied');
                    }
                    const emptyState = document.getElementById('browseEmptyState');
                    if (payload.count === 0 && emptyState) {
                        emptyState.classList.remove('hidden');
                    } else if (emptyState) {
                        emptyState.classList.add('hidden');
                    }
                } else {
                    showToast('error', payload.message || 'Failed to apply filters');
                }
            } catch (err) {
                showToast('error', err.message || 'Network failure');
            } finally {
                hideButtonLoading(submitBtn);
            }
        });
    }
    function initBookingForm() {
        const form = document.querySelector('form[action="cart.php"]');
        if (!form) return;
        if (form.dataset.custBookingAttached) return;
        form.dataset.custBookingAttached = '1';
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            const submitBtn = form.querySelector('button[type="submit"]');
            showButtonLoading(submitBtn);
            const fd = new FormData(form);
            try {
                const payload = await postForm(form.getAttribute('action'), fd);
                if (payload && payload.success) {
                    showToast('success', payload.message || 'Added to cart');
                } else {
                    showToast('error', payload.message || 'Unable to add to cart');
                }
            } catch (err) {
                showToast('error', err.message || 'Network error');
            } finally {
                hideButtonLoading(submitBtn);
            }
        });
    }
    function initCartRemove() {
        document.addEventListener('click', async function (e) {
            const btn = e.target.closest('[data-cart-remove]');
            if (!btn) return;
            const cartId = btn.getAttribute('data-cart-remove');
            if (!cartId) return;
            e.preventDefault();
            const fd = new FormData();
            const csrf = document.querySelector('input[name="csrf_token"]');
            if (csrf) fd.append('csrf_token', csrf.value);
            fd.append('action', 'remove');
            fd.append('cart_id', cartId);
            showButtonLoading(btn);
            try {
                const payload = await postForm('cart.php', fd);
                if (payload && payload.success) {
                    const row = btn.closest('[data-cart-row]');
                    if (row) {
                        row.style.transition = 'opacity 220ms ease, transform 220ms ease';
                        row.style.opacity = '0';
                        row.style.transform = 'translateX(30px)';
                        setTimeout(() => row.remove(), 260);
                    }
                    if (payload.totalsHtml && document.querySelector('.booking-summary')) {
                        document.querySelector('.booking-summary').innerHTML = payload.totalsHtml;
                    }
                    showToast('success', payload.message || 'Removed from cart');
                } else {
                    showToast('error', payload.message || 'Unable to remove');
                }
            } catch (err) {
                showToast('error', err.message || 'Network failure');
            } finally {
                hideButtonLoading(btn);
            }
        });
    }
    document.addEventListener('DOMContentLoaded', function () {
        initNewsletterForms();
        initBrowseFilters();
        initBookingForm();
        initCartRemove();
    });
    window.CustUI = {
        showToast,
        showButtonLoading,
        hideButtonLoading
    };
})();
