// Customer UI utilities: toast notifications, loader handlers, async form handlers, and real-time tracking
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
            el.style.display = 'flex';
            el.style.flexDirection = 'column';
            el.style.alignItems = 'flex-end';
            el.style.gap = '10px';
            document.body.appendChild(el);
        }
        return el;
    }

    function showToast(type, message, timeout = 5000) {
        if (!message) return;
        const container = ensureToastContainer();
        const toast = document.createElement('div');
        toast.className = 'cust-toast';
        toast.style.minWidth = '240px';
        toast.style.padding = '12px 14px';
        toast.style.borderRadius = '10px';
        toast.style.boxShadow = '0 10px 30px rgba(0,0,0,0.16)';
        toast.style.color = '#fff';
        toast.style.fontFamily = "Segoe UI, Open Sans, system-ui, -apple-system, 'Helvetica Neue', Arial";
        toast.style.fontSize = '13px';
        toast.style.maxWidth = '320px';
        toast.style.wordBreak = 'break-word';
        toast.style.opacity = '1';
        toast.style.transform = 'translateY(0)';
        toast.style.transition = 'opacity 220ms ease-in-out, transform 220ms ease-in-out';

        if (type === 'success') toast.style.background = '#16a34a';
        else if (type === 'warning') toast.style.background = '#f59e0b';
        else toast.style.background = '#dc2626';

        toast.textContent = message;
        container.appendChild(toast);

        window.setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-8px)';
            window.setTimeout(() => { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 260);
        }, timeout);
    }

    function spinnerElement(size = 14) {
        const span = document.createElement('span');
        span.style.display = 'inline-block';
        span.style.width = size + 'px';
        span.style.height = size + 'px';
        span.style.border = Math.max(2, Math.floor(size / 8)) + 'px solid rgba(255,255,255,0.45)';
        span.style.borderTopColor = 'rgba(255,255,255,1)';
        span.style.borderRadius = '50%';
        span.style.animation = 'cust-spin 800ms linear infinite';
        return span;
    }

    if (!document.getElementById('cust-ui-keyframes')) {
        const style = document.createElement('style');
        style.id = 'cust-ui-keyframes';
        style.textContent = '@keyframes cust-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }';
        document.head.appendChild(style);
    }

    function showButtonLoading(button) {
        if (!button) return;
        button._custOrigDisabled = button.disabled;
        button.disabled = true;
        button._custOrigContent = button.innerHTML;
        button.innerHTML = '';
        button.appendChild(spinnerElement(14));
        const text = document.createElement('span');
        text.style.marginLeft = '8px';
        text.style.fontWeight = '700';
        text.style.fontSize = '13px';
        text.textContent = 'Please wait';
        button.appendChild(text);
    }

    function hideButtonLoading(button) {
        if (!button) return;
        button.disabled = !!button._custOrigDisabled;
        if (button._custOrigContent !== undefined) button.innerHTML = button._custOrigContent;
        button._custOrigContent = undefined;
        button._custOrigDisabled = undefined;
    }

    async function postForm(url, formData) {
        const res = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            body: formData
        });
        if (!res.ok) throw new Error('Network error: ' + res.status);
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

    async function getCsrfToken(context = 'default') {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.content;
        return '';
    }

    // Real-time booking status polling
    function initBookingStatusTracking() {
        const container = document.querySelector('[data-booking-tracker]');
        if (!container) return;

        const bookingId = container.getAttribute('data-booking-id');
        if (!bookingId) return;

        const statusPoll = async () => {
            try {
                const res = await fetch('api/booking-status.php?booking_id=' + encodeURIComponent(bookingId), {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                });
                if (!res.ok) return;

                const contentType = res.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) return;

                const payload = await res.json();
                if (payload && payload.success && payload.data) {
                    const booking = payload.data;
                    const statusEl = container.querySelector('[data-booking-status]');
                    if (statusEl && statusEl.textContent !== booking.status) {
                        statusEl.textContent = booking.status;
                        const statusBadge = container.querySelector('[data-status-badge]');
                        if (statusBadge) {
                            statusBadge.className = 'text-[10px] font-black px-3 py-1 rounded-full uppercase ';
                            if (booking.status === 'Pending') {
                                statusBadge.className += 'bg-yellow-100 text-yellow-700';
                            } else if (booking.status === 'Confirmed' || booking.status === 'Active') {
                                statusBadge.className += 'bg-green-100 text-green-700';
                            } else if (booking.status === 'Completed') {
                                statusBadge.className += 'bg-blue-100 text-blue-700';
                            } else if (booking.status === 'Cancelled') {
                                statusBadge.className += 'bg-red-100 text-red-700';
                            }
                            statusBadge.textContent = booking.status;
                        }
                        if (booking.flags && booking.flags.is_overdue && statusEl.getAttribute('data-notified-overdue') !== 'true') {
                            showToast('warning', 'Your rental is overdue. Please contact the owner.');
                            statusEl.setAttribute('data-notified-overdue', 'true');
                        }
                    }
                }
            } catch (err) {
                console.warn('Booking status poll failed:', err.message);
            }
        };

        statusPoll();
        setInterval(statusPoll, 30000);
    }

    // GPS tracking visualization
    function initGpsTracking() {
        const container = document.querySelector('[data-gps-tracker]');
        if (!container) return;

        const bookingId = container.getAttribute('data-booking-id');
        if (!bookingId) return;

        const gpsPoll = async () => {
            try {
                const res = await fetch('api/gps-tracking.php?booking_id=' + encodeURIComponent(bookingId), {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                });
                if (!res.ok) return;

                const contentType = res.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) return;

                const payload = await res.json();
                if (payload && payload.success && payload.data) {
                    const tracking = payload.data;
                    const latEl = container.querySelector('[data-current-latitude]');
                    const lonEl = container.querySelector('[data-current-longitude]');
                    const routeEl = container.querySelector('[data-route-points]');

                    if (tracking.has_tracking && tracking.latitude && tracking.longitude) {
                        if (latEl) latEl.textContent = tracking.latitude.toFixed(4);
                        if (lonEl) lonEl.textContent = tracking.longitude.toFixed(4);

                        if (routeEl && tracking.route_history && tracking.route_history.length > 0) {
                            routeEl.textContent = tracking.route_history.length + ' waypoints tracked';
                        }

                        const mapContainer = container.querySelector('[data-map-embed]');
                        if (mapContainer && !mapContainer.querySelector('iframe')) {
                            const googleMapsUrl = 'https://www.google.com/maps/embed/v1/place?key=AIzaSyCfCFYGiWF4wH6rjqOjMvQPWGHwZGxBHp0&q=' + encodeURIComponent(tracking.latitude + ',' + tracking.longitude);
                            const iframe = document.createElement('iframe');
                            iframe.src = googleMapsUrl;
                            iframe.style.width = '100%';
                            iframe.style.height = '300px';
                            iframe.style.border = 'none';
                            iframe.style.borderRadius = '1rem';
                            iframe.allowFullscreen = '';
                            iframe.loading = 'lazy';
                            mapContainer.appendChild(iframe);
                        }

                        if (tracking.geofence_violation) {
                            showToast('warning', 'Geofence violation detected!', 7000);
                        }
                    } else if (routeEl) {
                        routeEl.textContent = 'No GPS data available yet';
                    }
                }
            } catch (err) {
                console.warn('GPS tracking poll failed:', err.message);
            }
        };

        gpsPoll();
        setInterval(gpsPoll, 45000);
    }

    // Live notifications
    function initLiveNotifications() {
        const container = document.querySelector('[data-notifications-container]');
        if (!container) return;

        const notificationsPoll = async () => {
            try {
                const res = await fetch('api/get-notifications.php?limit=10&unread_only=false', {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                });
                if (!res.ok) return;

                const contentType = res.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) return;

                const payload = await res.json();
                if (payload && payload.success && payload.data) {
                    const notifData = payload.data;
                    const unreadBadge = document.querySelector('[data-unread-count]');
                    if (unreadBadge) {
                        unreadBadge.textContent = notifData.unread_count || '';
                        if (notifData.unread_count > 0) {
                            unreadBadge.style.display = 'inline-block';
                        } else {
                            unreadBadge.style.display = 'none';
                        }
                    }
                }
            } catch (err) {
                console.warn('Notifications poll failed:', err.message);
            }
        };

        notificationsPoll();
        setInterval(notificationsPoll, 60000);
    }

    // Return vehicle modal
    function initReturnVehicleModal() {
        const returnBtn = document.querySelector('[data-initiate-return-btn]');
        if (!returnBtn) return;

        const modal = document.querySelector('[data-return-modal]');
        if (!modal) return;

        const closeBtn = modal.querySelector('[data-close-modal]');
        const submitBtn = modal.querySelector('[data-submit-return]');

        const closeModal = () => {
            modal.classList.remove('active');
            document.body.style.overflow = 'auto';
        };

        if (closeBtn) {
            closeBtn.addEventListener('click', closeModal);
        }

        returnBtn.addEventListener('click', () => {
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        });

        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                closeModal();
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal.classList.contains('active')) {
                closeModal();
            }
        });

        if (submitBtn) {
            submitBtn.addEventListener('click', async (e) => {
                e.preventDefault();
                const bookingId = returnBtn.getAttribute('data-booking-id');
                const locationInput = modal.querySelector('[data-return-location]');
                const notesInput = modal.querySelector('[data-return-notes]');
                const location = locationInput ? locationInput.value : '';
                const notes = notesInput ? notesInput.value : '';

                if (!location.trim()) {
                    showToast('error', 'Please specify a return location.');
                    return;
                }

                showButtonLoading(submitBtn);
                const csrf = await getCsrfToken('initiate-return');
                const fd = new FormData();
                fd.append('csrf_token', csrf);
                fd.append('booking_id', bookingId);
                fd.append('return_location', location);
                fd.append('return_notes', notes);

                try {
                    const response = await postForm('api/initiate-return.php', fd);
                    if (response && response.success) {
                        showToast('success', response.message || 'Return initiated successfully.');
                        modal.style.display = 'none';
                        document.body.style.overflow = 'auto';
                        if (locationInput) locationInput.value = '';
                        if (notesInput) notesInput.value = '';
                        setTimeout(() => window.location.reload(), 2000);
                    } else {
                        showToast('error', response && response.message ? response.message : 'Failed to initiate return.');
                    }
                } catch (err) {
                    showToast('error', err.message || 'Network error.');
                } finally {
                    hideButtonLoading(submitBtn);
                }
            });
        }
    }

    function initNewsletterForms() {
        Array.from(document.querySelectorAll('form')).forEach(form => {
            if (form.dataset.custHandled) return;

            const isNewsletterOptIn = form.dataset.newsletterForm === 'true'
                || form.classList.contains('newsletter-form')
                || form.classList.contains('cta-form');
            if (!isNewsletterOptIn || form.classList.contains('registration-form')) return;

            const csrf = form.querySelector('input[name="csrf_token"]');
            const email = form.querySelector('input[type="email"]');
            if (!csrf || !email) return;

            form.dataset.custHandled = '1';
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                const submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');
                showButtonLoading(submitBtn);
                const fd = new FormData(form);
                fd.set('email', email.value || '');
                fd.set('csrf_token', csrf.value || '');
                postForm(form.getAttribute('action') || window.location.pathname, fd).then(payload => {
                    if (payload && payload.success) {
                        showToast('success', payload.message || 'Subscribed successfully');
                        email.value = '';
                    } else {
                        showToast('error', payload && payload.message ? payload.message : 'Subscription failed');
                    }
                }).catch(err => showToast('error', err.message || 'Network failure')).finally(() => hideButtonLoading(submitBtn));
            });
        });
    }

    function initBrowseFilters() {
        const form = document.querySelector('form select[name="type"]')?.closest('form');
        if (!form || form.dataset.custFiltersAttached) return;
        form.dataset.custFiltersAttached = '1';
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const submitBtn = form.querySelector('button[type="submit"]');
            showButtonLoading(submitBtn);
            const fd = new FormData(form);
            postForm(new URL('filter-vehicles.php', window.location.href).toString(), fd).then(payload => {
                if (payload && payload.success) {
                    const container = document.querySelector('#Featured-v .grid.grid-cols-1') || document.querySelector('#Featured-v > div.grid');
                    if (container && payload.html) container.innerHTML = payload.html;
                    const emptyState = document.getElementById('browseEmptyState');
                    if (payload.count === 0 && emptyState) emptyState.classList.remove('hidden');
                    else if (emptyState) emptyState.classList.add('hidden');
                    showToast('success', payload.message || 'Filters applied');
                } else showToast('error', payload && payload.message ? payload.message : 'Failed to apply filters');
            }).catch(err => showToast('error', err.message || 'Network failure')).finally(() => hideButtonLoading(submitBtn));
        });
    }

    function initBookingForm() {
        const form = document.querySelector('form[action="cart.php"]');
        if (!form || form.dataset.custBookingAttached) return;
        form.dataset.custBookingAttached = '1';
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const submitBtn = form.querySelector('button[type="submit"]');
            showButtonLoading(submitBtn);
            const fd = new FormData(form);
            postForm(form.getAttribute('action'), fd).then(payload => {
                if (payload && payload.success) {
                    showToast('success', payload.message || 'Added to cart');
                    setTimeout(() => { window.location.href = 'cart.php'; }, 1000);
                } else {
                    showToast('error', payload.message || 'Unable to add to cart');
                    hideButtonLoading(submitBtn);
                }
            }).catch(err => {
                showToast('error', err.message || 'Network error');
                hideButtonLoading(submitBtn);
            });
        });
    }

    function initCartCheckout() {
        const form = document.querySelector('form[action="payment.php"]');
        if (!form || form.dataset.custCheckoutAttached) return;
        form.dataset.custCheckoutAttached = '1';
        form.addEventListener('submit', function (e) {
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) showButtonLoading(submitBtn);
        });
    }

    function initCartRemove() {
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('[data-cart-remove]');
            if (!btn) return;
            const cartId = btn.getAttribute('data-cart-remove');
            if (!cartId) return;
            e.preventDefault();
            const fd = new FormData();
            const csrf = document.querySelector('input[name="csrf_token"], input#customer-cart-csrf');
            if (csrf && csrf.value) {
                fd.append('csrf_token', csrf.value);
            }
            fd.append('action', 'remove');
            fd.append('cart_id', cartId);
            showButtonLoading(btn);
            postForm('cart.php', fd).then(payload => {
                if (payload && payload.success) {
                    const row = btn.closest('[data-cart-row]');
                    if (row) {
                        row.style.transition = 'opacity 220ms ease, transform 220ms ease';
                        row.style.opacity = '0';
                        row.style.transform = 'translateX(30px)';
                        setTimeout(() => row.remove(), 260);
                    }
                    if (payload.totalsHtml && document.querySelector('.booking-summary')) document.querySelector('.booking-summary').innerHTML = payload.totalsHtml;
                    showToast('success', payload.message || 'Removed from cart');
                } else showToast('error', payload.message || 'Unable to remove');
            }).catch(err => showToast('error', err.message || 'Network failure')).finally(() => hideButtonLoading(btn));
        });
    }

    function initCustomerUi() {
        initNewsletterForms();
        initBrowseFilters();
        initBookingForm();
        initCartCheckout();
        initCartRemove();
        initBookingStatusTracking();
        initGpsTracking();
        initLiveNotifications();
        initReturnVehicleModal();
    }

    document.addEventListener('DOMContentLoaded', initCustomerUi);

    window.CustUI = {
        showToast,
        showButtonLoading,
        hideButtonLoading,
        postForm,
        getCsrfToken
    };
})();
