(function () {
    'use strict';

    const adminApp = {
        state: {
            currentPage: 1,
            pageSize: 10,
            activeTab: null,
            selectedRows: [],
            sortColumn: null,
            sortDirection: 'asc',
            lastSearch: '',
            alerts: []
        },

        showToast: function (type, message) {
            if (!message) return;
            var container = document.getElementById('admin-toast-container');
            if (!container) {
                container = document.createElement('div');
                container.id = 'admin-toast-container';
                container.style.position = 'fixed';
                container.style.right = '16px';
                container.style.top = '16px';
                container.style.zIndex = '9999';
                container.style.display = 'flex';
                container.style.flexDirection = 'column';
                container.style.alignItems = 'flex-end';
                container.style.gap = '10px';
                document.body.appendChild(container);
            }
            var toast = document.createElement('div');
            toast.style.minWidth = '240px';
            toast.style.padding = '12px 14px';
            toast.style.borderRadius = '10px';
            toast.style.boxShadow = '0 10px 30px rgba(0,0,0,0.16)';
            toast.style.color = '#fff';
            toast.style.fontFamily = "Segoe UI, Open Sans, system-ui, -apple-system, 'Helvetica Neue', Arial";
            toast.style.fontSize = '13px';
            toast.style.opacity = '1';
            toast.style.transform = 'translateY(0)';
            toast.style.transition = 'opacity 220ms ease-in-out, transform 220ms ease-in-out';
            toast.style.background = type === 'success' ? '#16a34a' : type === 'warning' ? '#f59e0b' : '#dc2626';
            toast.textContent = message;
            container.appendChild(toast);
            window.setTimeout(function () {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(-8px)';
                window.setTimeout(function () {
                    if (toast.parentNode) toast.parentNode.removeChild(toast);
                }, 260);
            }, 4000);
        },

        init: function () {
            this.attachShellInteractions();
            this.attachUserManagementActions();
            this.attachVehicleManagementActions();
            this.attachBookingAndPayments();
            this.attachSupportComposer();
            this.attachTelemetryRenderer();
            this.attachTabSystem();
            this.attachSearchFunctionality();
            this.attachTableSorting();
            this.attachRowSelection();
            this.attachFormValidation();
            this.attachKeyboardNavigation();
            this.attachPaginationControls();
            this.attachBulkActions();
            this.attachExportFunctionality();
            this.attachCopyToClipboard();
            this.attachModalManagement();
            this.attachStatusIndicators();
            this.attachFormConfirmations();
            this.attachToggleSwitches();
            this.attachInsuranceClaimActions();
        },

        attachFormConfirmations: function () {
            const self = this;
            document.querySelectorAll('form[data-confirm]').forEach(function (form) {
                if (form.dataset._confirmAttached === '1') return;
                form.dataset._confirmAttached = '1';
                form.addEventListener('submit', function (event) {
                    event.preventDefault();
                    const msg = form.getAttribute('data-confirm') || 'Are you sure?';
                    const label = form.getAttribute('data-confirm-label') || 'Confirm';
                    self.confirmAction({
                        title: label,
                        message: msg,
                        confirmLabel: label,
                        onConfirm: function () { form.submit(); }
                    });
                });
            });
        },

        attachShellInteractions: function () {
            const header = document.querySelector('header');
            const sidebar = document.querySelector('aside');
            const mainContent = document.querySelector('main') || document.body;

            if (header && !header.querySelector('[data-admin-mobile-toggle]')) {
                const toggle = document.createElement('button');
                toggle.type = 'button';
                toggle.setAttribute('data-admin-mobile-toggle', 'true');
                toggle.className = 'lg:hidden inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 shadow-sm';
                toggle.innerHTML = '<svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>';
                header.insertBefore(toggle, header.firstChild);
            }

            const mobileToggle = document.querySelector('[data-admin-mobile-toggle]');
            if (mobileToggle && sidebar) {
                mobileToggle.addEventListener('click', function () {
                    sidebar.classList.toggle('-translate-x-full');
                    sidebar.classList.toggle('translate-x-0');
                    document.body.classList.toggle('overflow-hidden');
                });
            }

            if (sidebar) {
                sidebar.addEventListener('click', function (event) {
                    const link = event.target.closest('a');
                    if (!link) {
                        return;
                    }
                    if (window.innerWidth < 1024) {
                        sidebar.classList.add('-translate-x-full');
                        sidebar.classList.remove('translate-x-0');
                        document.body.classList.remove('overflow-hidden');
                    }
                });
            }

            const profileTrigger = header ? header.querySelector('.flex.items-center.gap-3.border-l') : null;
            if (profileTrigger && !header.querySelector('[data-admin-profile-panel]')) {
                profileTrigger.setAttribute('data-admin-profile-trigger', 'true');
                profileTrigger.style.cursor = 'pointer';
                const panel = document.createElement('div');
                panel.setAttribute('data-admin-profile-panel', 'true');
                panel.className = 'hidden absolute right-0 top-full mt-3 w-56 rounded-2xl border border-slate-200 bg-white p-3 shadow-xl';
                panel.innerHTML = [
                    '<div class="rounded-xl bg-slate-50 p-3 text-sm">',
                    '<p class="font-semibold text-slate-800">Secure admin access</p>',
                    '<p class="mt-1 text-xs text-slate-500">Monitoring, compliance, and escalation workflows</p>',
                    '</div>',
                    '<a href="logout.php" class="mt-3 flex items-center justify-between rounded-xl px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-50">',
                    '<span>Terminate session</span><span>↗</span>',
                    '</a>'
                ].join('');
                profileTrigger.style.position = 'relative';
                profileTrigger.appendChild(panel);
            }

            if (profileTrigger) {
                profileTrigger.addEventListener('click', function (event) {
                    if (event.target.closest('a')) {
                        return;
                    }
                    const panel = header.querySelector('[data-admin-profile-panel]');
                    if (panel) {
                        panel.classList.toggle('hidden');
                    }
                });
            }

            document.addEventListener('click', function (event) {
                const panel = document.querySelector('[data-admin-profile-panel]');
                const trigger = document.querySelector('[data-admin-profile-trigger]');
                if (!panel || !trigger) {
                    return;
                }
                if (!trigger.contains(event.target) && !panel.contains(event.target)) {
                    panel.classList.add('hidden');
                }
            });

            document.querySelectorAll('select, input[type="text"], input[type="search"], textarea').forEach(function (field) {
                field.addEventListener('focus', function () {
                    const wrapper = field.closest('form');
                    if (wrapper) {
                        wrapper.classList.add('ring-1', 'ring-[#1b4b4b]/20');
                    }
                });
                field.addEventListener('blur', function () {
                    const wrapper = field.closest('form');
                    if (wrapper) {
                        wrapper.classList.remove('ring-1', 'ring-[#1b4b4b]/20');
                    }
                });
            });

            const broadcastTrigger = document.querySelector('[data-open-broadcast-form]');
            if (broadcastTrigger) {
                broadcastTrigger.addEventListener('click', function () {
                    const form = document.querySelector('form[data-broadcast-form]');
                    if (form) {
                        form.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        form.querySelector('[name="message_title"]')?.focus();
                    }
                });
            }

            if (mainContent) {
                mainContent.addEventListener('click', function (event) {
                    const toggleTarget = event.target.closest('[data-admin-toggle-panel]');
                    if (toggleTarget) {
                        const panel = document.getElementById(toggleTarget.getAttribute('data-admin-toggle-panel'));
                        if (panel) {
                            panel.classList.toggle('hidden');
                        }
                    }
                });
            }
        },

        attachUserManagementActions: function () {
            const forms = Array.prototype.slice.call(document.querySelectorAll('form')).filter(function (form) {
                return form.querySelector('input[name="user_id"]') && form.querySelector('input[name="admin_action"]');
            });

            forms.forEach(function (form) {
                const button = form.querySelector('button[type="submit"]');
                if (!button) {
                    return;
                }
                if (form.dataset.userManagementAttached === '1') return;
                form.dataset.userManagementAttached = '1';

                button.addEventListener('click', function (event) {
                    event.preventDefault();
                    const action = form.querySelector('input[name="admin_action"]').value;
                    const userId = form.querySelector('input[name="user_id"]').value;
                    const actionLabel = action === 'suspend' ? 'Suspend' : 'Approve';
                    const message = 'This action will update account #' + userId + '.';
                    const csrf = form.querySelector('input[name="csrf_token"]').value;

                    this.confirmAction({
                        title: actionLabel + ' user account',
                        message: message,
                        confirmLabel: actionLabel,
                        onConfirm: function () {
                            button.disabled = true;
                            const originalButtonHtml = button.innerHTML;
                            button.innerHTML = '<span class="inline-flex h-3.5 w-3.5 animate-spin rounded-full border-2 border-current border-t-transparent mr-2"></span> Processing';

                            const fd = new FormData();
                            fd.append('user_id', userId);
                            fd.append('admin_action', action);
                            fd.append('csrf_token', csrf);

                            fetch(window.location.pathname, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: { 'Accept': 'application/json' },
                                body: fd
                            }).then(function (response) {
                                if (!response.ok) throw new Error('Network error: ' + response.status);
                                const contentType = response.headers.get('content-type');
                                if (!contentType || !contentType.includes('application/json')) {
                                    throw new Error('Server returned non-JSON response.');
                                }
                                return response.json();
                            }).then(function (payload) {
                                if (!payload || !payload.success) {
                                    throw new Error(payload && payload.message ? payload.message : 'Action failed');
                                }
                                const row = form.closest('tr');
                                if (row) {
                                    const badge = row.querySelector('span.inline-flex.items-center');
                                    if (badge) {
                                        const status = action === 'suspend' ? 'Suspended' : 'Active';
                                        badge.className = 'inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold ' + (status === 'Suspended' ? 'bg-red-50 text-red-600' : 'bg-emerald-50 text-emerald-600');
                                        badge.textContent = status;
                                    }
                                }
                                if (payload.notice) {
                                    this.showToast('success', payload.notice);
                                } else {
                                    this.showToast('success', actionLabel + ' successful');
                                }
                            }.bind(this)).catch(function (error) {
                                this.showToast('error', error.message || 'Unable to complete action');
                            }.bind(this)).finally(function () {
                                button.disabled = false;
                                button.innerHTML = originalButtonHtml;
                            });
                        }.bind(this)
                    });
                }.bind(this));
            }, this);
        },

        attachVehicleManagementActions: function () {
            const forms = Array.prototype.slice.call(document.querySelectorAll('form')).filter(function (form) {
                return form.querySelector('input[name="vehicle_id"]') && form.querySelector('input[name="vehicle_action"]');
            });

            forms.forEach(function (form) {
                const button = form.querySelector('button[type="submit"]');
                if (!button) {
                    return;
                }
                button.addEventListener('click', function (event) {
                    event.preventDefault();
                    const action = form.querySelector('input[name="vehicle_action"]').value;
                    const vehicleId = form.querySelector('input[name="vehicle_id"]').value;
                    const actionLabel = action === 'reject' ? 'Reject' : 'Approve';
                    const message = 'This action will change the vehicle verification state for vehicle ' + vehicleId + ' and update the listing readiness.';
                    this.confirmAction({
                        title: actionLabel + ' vehicle verification',
                        message: message,
                        confirmLabel: actionLabel,
                        onConfirm: function () {
                            form.classList.add('is-submitting');
                            button.disabled = true;
                            button.innerHTML = '<span class="inline-flex h-3.5 w-3.5 animate-spin rounded-full border-2 border-current border-t-transparent"></span> Processing';
                            setTimeout(function () {
                                form.submit();
                            }, 220);
                        }
                    });
                }.bind(this));
            }, this);

            document.querySelectorAll('a[href], [data-file-link], [data-vehicle-file]').forEach(function (link) {
                link.addEventListener('click', function (event) {
                    const href = link.getAttribute('href') || link.getAttribute('data-file-link') || link.getAttribute('data-vehicle-file') || '';
                    if (!href || !/\.(png|jpe?g|gif|webp|pdf|docx?|xlsx?|pptx?)$/i.test(href)) {
                        return;
                    }
                    event.preventDefault();
                    this.openFileViewer(href);
                }.bind(this));
            }, this);
        },

        attachBookingAndPayments: function () {
            document.querySelectorAll('form').forEach(function (form) {
                const bookingSelect = form.querySelector('select[name="booking_action"]');
                if (bookingSelect) {
                    bookingSelect.addEventListener('change', function () {
                        const row = form.closest('tr');
                        if (row) {
                            row.classList.add('bg-amber-50/70');
                        }
                    });
                    form.addEventListener('submit', function (event) {
                        const row = form.closest('tr');
                        if (row) {
                            row.classList.add('bg-amber-50/70');
                            row.querySelectorAll('button, select').forEach(function (field) {
                                field.classList.add('opacity-80');
                            });
                        }
                    });
                }
            });

            document.querySelectorAll('button[name="payment_action"]').forEach(function (button) {
                button.addEventListener('click', function () {
                    const action = button.getAttribute('value');
                    const row = button.closest('tr');
                    if (row) {
                        row.classList.add('bg-emerald-50/70');
                    }
                    button.textContent = action === 'refund' ? 'Refunding…' : 'Releasing…';
                    button.classList.add('opacity-70');
                });
            });
        },

        attachSupportComposer: function () {
            const composerForm = document.querySelector('form[data-support-composer]');

            if (!composerForm) {
                return;
            }

            composerForm.addEventListener('submit', function (event) {
                const textarea = composerForm.querySelector('textarea');
                const inputText = (textarea ? textarea.value : '').trim();
                if (!inputText) {
                    event.preventDefault();
                    return;
                }

                const thread = document.querySelector('.custom-scrollbar') || document.querySelector('main') || document.body;
                const bubble = document.createElement('div');
                bubble.className = 'ml-auto flex max-w-2xl flex-row-reverse gap-4';
                bubble.innerHTML = [
                    '<div class="h-8 w-8 shrink-0 rounded-lg bg-[#1b4b4b] text-[10px] font-bold text-white flex items-center justify-center">A</div>',
                    '<div class="rounded-2xl rounded-tr-none border border-emerald-100 bg-emerald-50 p-4 shadow-sm">',
                    '<p class="text-xs leading-relaxed text-slate-700">' + this.escapeHtml(inputText) + '</p>',
                    '<p class="mt-2 text-[8px] font-bold uppercase tracking-widest text-emerald-700">Response queued</p>',
                    '</div>'
                ].join('');
                thread.appendChild(bubble);

                textarea.value = '';
                if (thread.scrollTopMax !== undefined) {
                    thread.scrollTop = thread.scrollHeight;
                }

                setTimeout(function () {
                    composerForm.submit();
                }, 80);
                event.preventDefault();
            }.bind(this));
        },

        attachTelemetryRenderer: function () {
            window.SmartRentalAdminTelemetry = {
                parseRouteHistory: function (rawValue) {
                    if (!rawValue) {
                        return [];
                    }
                    try {
                        const parsed = JSON.parse(rawValue);
                        if (Array.isArray(parsed)) {
                            return parsed;
                        }
                        if (parsed && typeof parsed === 'object' && Array.isArray(parsed.route_history)) {
                            return parsed.route_history;
                        }
                    } catch (error) {
                        return [];
                    }
                    return [];
                },
                renderRouteSummary: function (container, routeHistory) {
                    const points = Array.isArray(routeHistory) ? routeHistory : [];
                    if (!container) {
                        return;
                    }
                    const summary = document.createElement('div');
                    summary.className = 'mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-3 text-[10px] font-semibold uppercase tracking-wider text-slate-600';
                    const firstPoint = points[0] || null;
                    const lastPoint = points[points.length - 1] || null;
                    const label = firstPoint && lastPoint
                        ? 'Route: ' + firstPoint.lat + ' → ' + lastPoint.lat + ' | ' + points.length + ' points'
                        : 'Route telemetry pending';
                    summary.innerHTML = '<span class="text-[#1b4b4b]">Telemetry</span> · ' + this.escapeHtml(label);
                    container.appendChild(summary);
                },
                escapeHtml: function (value) {
                    return String(value)
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;')
                        .replace(/'/g, '&#39;');
                }
            };

            const telemetryContainers = Array.prototype.slice.call(document.querySelectorAll('[data-route-history], .map-placeholder, .route-history-panel'));
            const routeHistoryValue = document.body.getAttribute('data-route-history') || '';
            const parsedRouteHistory = window.SmartRentalAdminTelemetry.parseRouteHistory(routeHistoryValue);
            if (telemetryContainers.length) {
                telemetryContainers.forEach(function (container) {
                    window.SmartRentalAdminTelemetry.renderRouteSummary(container, parsedRouteHistory);
                });
            }
        },

        confirmAction: function (options) {
            const overlay = document.createElement('div');
            overlay.className = 'fixed inset-0 z-[120] flex items-center justify-center bg-slate-950/60 px-4';
            overlay.innerHTML = [
                '<div class="w-full max-w-md rounded-[2rem] border border-slate-200 bg-white p-7 shadow-2xl">',
                '<p class="text-[10px] font-black uppercase tracking-[0.3em] text-slate-400">Administrative action</p>',
                '<h3 class="mt-3 text-xl font-black text-slate-900">' + this.escapeHtml(options.title || 'Confirm action') + '</h3>',
                '<p class="mt-3 text-sm text-slate-600">' + this.escapeHtml(options.message || 'Please confirm this action before continuing.') + '</p>',
                '<div class="mt-6 flex justify-end gap-3">',
                '<button type="button" data-admin-cancel="true" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600">Cancel</button>',
                '<button type="button" data-admin-confirm="true" class="rounded-xl bg-[#1b4b4b] px-4 py-2 text-sm font-semibold text-white">' + this.escapeHtml(options.confirmLabel || 'Confirm') + '</button>',
                '</div>',
                '</div>'
            ].join('');
            document.body.appendChild(overlay);
            overlay.querySelector('[data-admin-cancel="true"]').addEventListener('click', function () {
                overlay.remove();
            });
            overlay.querySelector('[data-admin-confirm="true"]').addEventListener('click', function () {
                overlay.remove();
                if (typeof options.onConfirm === 'function') {
                    options.onConfirm();
                }
            });
        },

        openFileViewer: function (href) {
            const overlay = document.createElement('div');
            overlay.className = 'fixed inset-0 z-[130] flex items-center justify-center bg-slate-950/70 px-4';
            const extension = (href.split('.').pop() || '').toLowerCase();
            let content = '<div class="w-full max-w-4xl rounded-[2rem] bg-white p-4 shadow-2xl">';
            content += '<div class="mb-3 flex items-center justify-between"><h3 class="text-sm font-black uppercase tracking-widest text-slate-800">Compliance viewer</h3><button type="button" data-admin-close-viewer="true" class="rounded-full border border-slate-200 px-3 py-1 text-xs font-semibold text-slate-600">Close</button></div>';
            if (['png', 'jpg', 'jpeg', 'gif', 'webp'].indexOf(extension) !== -1) {
                content += '<img src="' + this.escapeHtml(href) + '" class="max-h-[70vh] w-full rounded-2xl object-contain bg-slate-50" alt="Compliance preview" />';
            } else if (['pdf'].indexOf(extension) !== -1) {
                content += '<iframe src="' + this.escapeHtml(href) + '" class="min-h-[70vh] w-full rounded-2xl border border-slate-200"></iframe>';
            } else {
                content += '<div class="flex min-h-[40vh] items-center justify-center rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-sm text-slate-600">Preview is not available for this file type. The document can still be opened directly from the provided link.</div>';
            }
            content += '</div>';
            overlay.innerHTML = content;
            document.body.appendChild(overlay);
            overlay.querySelector('[data-admin-close-viewer="true"]').addEventListener('click', function () {
                overlay.remove();
            });
        },

        escapeHtml: function (value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        },

        // ===== TAB/FILTER SYSTEM =====
        attachTabSystem: function () {
            const self = this;
            document.querySelectorAll('[data-tab-group]').forEach(function (group) {
                const buttons = group.querySelectorAll('button[data-tab]');
                buttons.forEach(function (button) {
                    button.addEventListener('click', function (event) {
                        event.preventDefault();
                        const tabName = button.getAttribute('data-tab');
                        const tabType = group.getAttribute('data-tab-group');
                        
                        buttons.forEach(function (btn) {
                            btn.classList.remove('bg-[#1b4b4b]', 'text-white', 'shadow-lg');
                            btn.classList.add('bg-white', 'text-gray-400', 'border', 'border-transparent');
                        });
                        
                        button.classList.remove('bg-white', 'text-gray-400', 'border', 'border-transparent');
                        button.classList.add('bg-[#1b4b4b]', 'text-white', 'shadow-lg');
                        
                        self.state.activeTab = tabName;
                        
                        const targetPanel = document.querySelector('[data-tab-panel="' + tabName + '"]');
                        if (targetPanel) {
                            self.filterTableByStatus(tabName);
                        }
                        
                        const event = new CustomEvent('admin:tab-changed', { 
                            detail: { tab: tabName, type: tabType } 
                        });
                        document.dispatchEvent(event);
                    });
                });
            });
        },

        filterTableByStatus: function (status) {
            const table = document.querySelector('table');
            if (!table) return;
            
            const rows = table.querySelectorAll('tbody tr');
            let visibleCount = 0;
            
            rows.forEach(function (row) {
                const statusCell = row.querySelector('[data-status], td:nth-child(5)');
                if (!statusCell) {
                    row.style.display = '';
                    visibleCount++;
                    return;
                }
                
                const rowStatus = statusCell.textContent.trim().toLowerCase().replace(/[^\w]/g, '');
                const filterStatus = status.toLowerCase().replace(/[^\w]/g, '');
                
                if (rowStatus.includes(filterStatus) || status === 'all' || status === 'All') {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            const emptyRow = table.querySelector('tbody tr[data-empty]');
            if (visibleCount === 0 && !emptyRow) {
                const tbody = table.querySelector('tbody');
                const newRow = document.createElement('tr');
                newRow.setAttribute('data-empty', 'true');
                newRow.innerHTML = '<td colspan="100" class="px-8 py-12 text-center text-sm text-slate-500"><div class="empty-state inline-block w-full"><div class="es-icon">📭</div><div>No records found for this filter.</div></div></td>';
                tbody.appendChild(newRow);
            }
        },

        // ===== SEARCH FUNCTIONALITY =====
        attachSearchFunctionality: function () {
            const self = this;
            const searchInput = document.querySelector('input[data-search-tickets], input[type="search"], input[placeholder*="Search"]');
            
            if (!searchInput) return;
            
            let searchTimeout;
            searchInput.addEventListener('input', function (event) {
                clearTimeout(searchTimeout);
                const query = event.target.value.trim();
                self.state.lastSearch = query;
                
                searchTimeout = setTimeout(function () {
                    self.performSearch(query);
                }, 300);
            });
            
            searchInput.addEventListener('keyup', function (event) {
                if (event.key === 'Enter') {
                    clearTimeout(searchTimeout);
                    self.performSearch(event.target.value.trim());
                }
            });
        },

        performSearch: function (query) {
            if (!query) {
                this.resetTableView();
                return;
            }
            
            const ticketItems = document.querySelectorAll('[data-ticket-item="true"]');
            if (ticketItems.length) {
                const queryLower = query.toLowerCase();
                let visibleCount = 0;

                ticketItems.forEach(function (item) {
                    const text = item.textContent.toLowerCase();
                    if (text.includes(queryLower)) {
                        item.style.display = '';
                        visibleCount++;
                    } else {
                        item.style.display = 'none';
                    }
                });

                const noResults = document.querySelector('[data-ticket-search-empty]');
                if (noResults) {
                    noResults.remove();
                }

                if (visibleCount === 0) {
                    const container = ticketItems[0].closest('.custom-scrollbar') || ticketItems[0].parentElement;
                    if (container) {
                        const emptyMessage = document.createElement('div');
                        emptyMessage.setAttribute('data-ticket-search-empty', 'true');
                        emptyMessage.className = 'p-6 text-sm text-slate-500';
                        emptyMessage.textContent = '';
                        emptyMessage.innerHTML = '<div class="empty-state"><div class="es-icon">🔍</div><div>No tickets match your search query.</div></div>';
                        container.appendChild(emptyMessage);
                    }
                }
                return;
            }
            
            const table = document.querySelector('table');
            if (!table) return;
            
            const rows = table.querySelectorAll('tbody tr');
            const queryLower = query.toLowerCase();
            let visibleCount = 0;
            
            rows.forEach(function (row) {
                const text = row.textContent.toLowerCase();
                if (text.includes(queryLower)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            if (visibleCount === 0) {
                const tbody = table.querySelector('tbody');
                const emptyRow = tbody.querySelector('tr[data-search-empty]');
                if (emptyRow) emptyRow.remove();
                
                const newRow = document.createElement('tr');
                newRow.setAttribute('data-search-empty', 'true');
                newRow.innerHTML = '<td colspan="100" class="px-8 py-12 text-center text-sm text-slate-500"><div class="empty-state inline-block w-full"><div class="es-icon">🔎</div><div>No results found for "' + this.escapeHtml(query) + '"</div></div></td>';
                tbody.appendChild(newRow);
            }
        },

        resetTableView: function () {
            const ticketItems = document.querySelectorAll('[data-ticket-item="true"]');
            if (ticketItems.length) {
                ticketItems.forEach(function (item) {
                    item.style.display = '';
                });
                const noResults = document.querySelector('[data-ticket-search-empty]');
                if (noResults) {
                    noResults.remove();
                }
            }

            const table = document.querySelector('table');
            if (!table) return;
            
            table.querySelectorAll('tbody tr[data-search-empty], tbody tr[data-empty]').forEach(function (row) {
                row.remove();
            });
            
            table.querySelectorAll('tbody tr').forEach(function (row) {
                row.style.display = '';
            });
        },

        // ===== TABLE SORTING =====
        attachTableSorting: function () {
            const self = this;
            const table = document.querySelector('table');
            if (!table) return;
            
            const headers = table.querySelectorAll('th');
            headers.forEach(function (header, index) {
                header.style.cursor = 'pointer';
                header.title = 'Click to sort';
                header.addEventListener('click', function () {
                    self.sortTable(index, header);
                });
            });
        },

        sortTable: function (columnIndex, headerElement) {
            const table = document.querySelector('table');
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr:not([data-empty]):not([data-search-empty])'));
            
            if (rows.length === 0) return;
            
            const direction = this.state.sortColumn === columnIndex && this.state.sortDirection === 'asc' ? 'desc' : 'asc';
            this.state.sortColumn = columnIndex;
            this.state.sortDirection = direction;
            
            rows.sort(function (a, b) {
                const aValue = a.children[columnIndex]?.textContent.trim() || '';
                const bValue = b.children[columnIndex]?.textContent.trim() || '';
                
                const aNum = parseFloat(aValue);
                const bNum = parseFloat(bValue);
                
                let comparison = 0;
                if (!isNaN(aNum) && !isNaN(bNum)) {
                    comparison = aNum - bNum;
                } else {
                    comparison = aValue.localeCompare(bValue);
                }
                
                return direction === 'asc' ? comparison : -comparison;
            });
            
            rows.forEach(function (row) {
                tbody.appendChild(row);
            });
            
            document.querySelectorAll('th').forEach(function (h, i) {
                h.classList.remove('font-black');
                if (i === columnIndex) {
                    h.classList.add('font-black');
                }
            });
        },

        // ===== ROW SELECTION =====
        attachRowSelection: function () {
            const self = this;
            
            // Add select all checkbox
            const thead = document.querySelector('thead tr');
            if (thead && !thead.querySelector('th:first-child input[type="checkbox"]')) {
                const selectAllTh = document.createElement('th');
                selectAllTh.className = 'px-4 py-5 w-8';
                const selectAllCheckbox = document.createElement('input');
                selectAllCheckbox.type = 'checkbox';
                selectAllCheckbox.className = 'w-4 h-4 rounded';
                selectAllTh.appendChild(selectAllCheckbox);
                
                selectAllCheckbox.addEventListener('change', function () {
                    const checkboxes = document.querySelectorAll('tbody tr:visible input[type="checkbox"]');
                    checkboxes.forEach(function (cb) {
                        cb.checked = selectAllCheckbox.checked;
                        const row = cb.closest('tr');
                        if (selectAllCheckbox.checked) {
                            row.classList.add('bg-blue-50');
                            self.state.selectedRows.push(row.dataset.rowId || row.textContent);
                        } else {
                            row.classList.remove('bg-blue-50');
                        }
                    });
                });
                
                thead.insertBefore(selectAllTh, thead.firstChild);
            }
            
            // Add checkbox to each row
            document.querySelectorAll('tbody tr:not([data-empty]):not([data-search-empty])').forEach(function (row) {
                if (!row.querySelector('input[type="checkbox"]')) {
                    const selectTd = document.createElement('td');
                    selectTd.className = 'px-4 py-6 w-8';
                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.className = 'w-4 h-4 rounded';
                    selectTd.appendChild(checkbox);
                    
                    checkbox.addEventListener('change', function () {
                        if (checkbox.checked) {
                            row.classList.add('bg-blue-50');
                            self.state.selectedRows.push(row.dataset.rowId || row.textContent);
                        } else {
                            row.classList.remove('bg-blue-50');
                            self.state.selectedRows = self.state.selectedRows.filter(function (r) {
                                return r !== (row.dataset.rowId || row.textContent);
                            });
                        }
                    });
                    
                    row.insertBefore(selectTd, row.firstChild);
                }
            });
        },

        // ===== FORM VALIDATION =====
        attachFormValidation: function () {
            const self = this;
            const forms = document.querySelectorAll('form[data-validate]');
            
            forms.forEach(function (form) {
                const inputs = form.querySelectorAll('input, select, textarea');
                
                inputs.forEach(function (input) {
                    input.addEventListener('blur', function () {
                        self.validateField(input);
                    });
                    
                    input.addEventListener('input', function () {
                        if (input.classList.contains('is-invalid')) {
                            self.validateField(input);
                        }
                    });
                });
                
                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.addEventListener('click', function (event) {
                        const isValid = self.validateForm(form);
                        if (!isValid) {
                            event.preventDefault();
                        }
                    });
                }
            });
        },

        validateField: function (field) {
            const value = field.value.trim();
            let isValid = true;
            let errorMsg = '';
            
            if (field.hasAttribute('required') && !value) {
                isValid = false;
                errorMsg = 'This field is required';
            } else if (field.type === 'email' && value && !this.isValidEmail(value)) {
                isValid = false;
                errorMsg = 'Please enter a valid email';
            } else if (field.type === 'number') {
                if (field.hasAttribute('min') && parseFloat(value) < parseFloat(field.getAttribute('min'))) {
                    isValid = false;
                    errorMsg = 'Value must be at least ' + field.getAttribute('min');
                }
                if (field.hasAttribute('max') && parseFloat(value) > parseFloat(field.getAttribute('max'))) {
                    isValid = false;
                    errorMsg = 'Value must be at most ' + field.getAttribute('max');
                }
            }
            
            if (isValid) {
                field.classList.remove('is-invalid', 'border-red-500');
                field.classList.add('border-emerald-500');
                const error = field.parentElement.querySelector('.error-message');
                if (error) error.remove();
            } else {
                field.classList.remove('border-emerald-500');
                field.classList.add('is-invalid', 'border-red-500');
                let error = field.parentElement.querySelector('.error-message');
                if (!error) {
                    error = document.createElement('p');
                    error.className = 'error-message text-xs text-red-500 mt-1';
                    field.parentElement.appendChild(error);
                }
                error.textContent = errorMsg;
            }
            
            return isValid;
        },

        validateForm: function (form) {
            let isValid = true;
            const inputs = form.querySelectorAll('input, select, textarea');
            
            inputs.forEach(function (input) {
                if (!this.validateField(input)) {
                    isValid = false;
                }
            }.bind(this));
            
            return isValid;
        },

        isValidEmail: function (email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        },

        // ===== KEYBOARD NAVIGATION =====
        attachKeyboardNavigation: function () {
            const self = this;
            
            document.addEventListener('keydown', function (event) {
                // Shift+Enter to submit forms (common in support/messaging)
                if (event.shiftKey && event.key === 'Enter') {
                    const form = document.querySelector('form textarea:focus')?.closest('form');
                    if (form) {
                        form.querySelector('button[type="submit"]')?.click();
                    }
                }
                
                // Escape to close modals
                if (event.key === 'Escape') {
                    const modal = document.querySelector('[data-modal]:not(.hidden)');
                    if (modal) {
                        modal.remove();
                    }
                }
                
                // Ctrl+K or Cmd+K for search focus
                if ((event.ctrlKey || event.metaKey) && event.key === 'k') {
                    event.preventDefault();
                    const searchInput = document.querySelector('input[type="search"], input[placeholder*="Search"]');
                    if (searchInput) searchInput.focus();
                }
            });
        },

        // ===== PAGINATION =====
        attachPaginationControls: function () {
            const self = this;
            document.querySelectorAll('[data-pagination]').forEach(function (container) {
                const prevBtn = container.querySelector('[data-page="prev"]');
                const nextBtn = container.querySelector('[data-page="next"]');
                
                if (prevBtn) {
                    prevBtn.addEventListener('click', function (event) {
                        event.preventDefault();
                        if (self.state.currentPage > 1) {
                            self.state.currentPage--;
                            self.loadPage(self.state.currentPage);
                        }
                    });
                }
                
                if (nextBtn) {
                    nextBtn.addEventListener('click', function (event) {
                        event.preventDefault();
                        self.state.currentPage++;
                        self.loadPage(self.state.currentPage);
                    });
                }
            });
        },

        loadPage: function (pageNum) {
            const url = new URL(window.location);
            url.searchParams.set('page', pageNum);
            window.location = url.toString();
        },

        // ===== BULK ACTIONS =====
        attachBulkActions: function () {
            const self = this;
            document.querySelectorAll('[data-bulk-action]').forEach(function (button) {
                button.addEventListener('click', function (event) {
                    event.preventDefault();
                    
                    if (self.state.selectedRows.length === 0) {
                        self.showAlert('Please select at least one item', 'warning');
                        return;
                    }
                    
                    const action = button.getAttribute('data-bulk-action');
                    self.performBulkAction(action, self.state.selectedRows);
                });
            });
        },

        performBulkAction: function (action, selectedRows) {
            if (action === 'delete') {
                this.confirmAction({
                    title: 'Bulk Delete',
                    message: 'This will delete ' + selectedRows.length + ' selected items. This action cannot be undone.',
                    confirmLabel: 'Delete All',
                    onConfirm: function () {
                        // Send to server via form submission or fetch
                    }
                });
            } else if (action === 'export') {
                this.exportSelectedRows(selectedRows);
            }
        },

        // ===== EXPORT FUNCTIONALITY =====
        attachExportFunctionality: function () {
            const self = this;
            document.querySelectorAll('[data-export]').forEach(function (button) {
                button.addEventListener('click', function (event) {
                    event.preventDefault();
                    const format = button.getAttribute('data-export');
                    self.exportTable(format);
                });
            });
        },

        exportTable: function (format) {
            const table = document.querySelector('table');
            if (!table) return;
            
            if (format === 'csv') {
                this.exportTableToCSV(table);
            } else if (format === 'pdf') {
                const pdfForm = document.createElement('form');
                pdfForm.method = 'POST';
                pdfForm.action = window.location.pathname;
                pdfForm.style.display = 'none';

                const pdfInput = document.createElement('input');
                pdfInput.type = 'hidden';
                pdfInput.name = 'export_pdf';
                pdfInput.value = '1';
                pdfForm.appendChild(pdfInput);

                const startDate = document.querySelector('input[name="filter_start_date"]');
                const endDate = document.querySelector('input[name="filter_end_date"]');
                if (startDate) {
                    const inputStart = document.createElement('input');
                    inputStart.type = 'hidden';
                    inputStart.name = 'filter_start_date';
                    inputStart.value = startDate.value;
                    pdfForm.appendChild(inputStart);
                }
                if (endDate) {
                    const inputEnd = document.createElement('input');
                    inputEnd.type = 'hidden';
                    inputEnd.name = 'filter_end_date';
                    inputEnd.value = endDate.value;
                    pdfForm.appendChild(inputEnd);
                }

                document.body.appendChild(pdfForm);
                pdfForm.submit();
                document.body.removeChild(pdfForm);
            }
        },

        exportTableToPDF: function (table) {
            // Server-generated PDF handled via form submission.
        },

        exportTableToCSV: function (table) {
            let csv = [];
            const rows = table.querySelectorAll('tr');
            
            rows.forEach(function (row) {
                const cells = row.querySelectorAll('td, th');
                const rowData = [];
                cells.forEach(function (cell) {
                    rowData.push('"' + cell.textContent.trim().replace(/"/g, '""') + '"');
                });
                csv.push(rowData.join(','));
            });
            
            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'export_' + new Date().getTime() + '.csv';
            link.click();
            URL.revokeObjectURL(url);
        },

        exportSelectedRows: function (selectedRows) {
            if (selectedRows.length === 0) return;
            this.exportTableToCSV(document.querySelector('table'));
        },

        // ===== COPY TO CLIPBOARD =====
        attachCopyToClipboard: function () {
            const self = this;
            document.querySelectorAll('[data-copy]').forEach(function (element) {
                element.style.cursor = 'pointer';
                element.title = 'Click to copy';
                
                element.addEventListener('click', function (event) {
                    event.stopPropagation();
                    const text = element.getAttribute('data-copy') || element.textContent.trim();
                    
                    navigator.clipboard.writeText(text).then(function () {
                        const originalText = element.textContent;
                        element.textContent = '✓ Copied!';
                        setTimeout(function () {
                            element.textContent = originalText;
                        }, 2000);
                    });
                });
            });
        },

        // ===== MODAL MANAGEMENT =====
        attachModalManagement: function () {
            const self = this;
            
            // Close modal on overlay click
            document.addEventListener('click', function (event) {
                if (event.target.hasAttribute('data-modal-overlay')) {
                    event.target.remove();
                }
            });
            
            // Close button handlers
            document.querySelectorAll('[data-modal-close]').forEach(function (button) {
                button.addEventListener('click', function (event) {
                    event.preventDefault();
                    const modal = event.target.closest('[data-modal]');
                    if (modal) modal.remove();
                });
            });
        },

        // ===== STATUS INDICATORS =====
        attachStatusIndicators: function () {
            document.querySelectorAll('[data-status-live]').forEach(function (element) {
                const status = element.getAttribute('data-status-live');
                
                const statusMap = {
                    'active': { class: 'text-green-600', icon: '●', animated: true },
                    'pending': { class: 'text-amber-600', icon: '◐', animated: false },
                    'verified': { class: 'text-blue-600', icon: '✓', animated: false },
                    'rejected': { class: 'text-red-600', icon: '✗', animated: false },
                    'suspended': { class: 'text-red-600', icon: '⚠', animated: false }
                };
                
                const config = statusMap[status.toLowerCase()] || statusMap.pending;
                element.classList.add(config.class);
                
                if (config.animated) {
                    const dot = element.querySelector('.status-dot');
                    if (dot) {
                        dot.classList.add('animate-pulse');
                    }
                }
            });
        },

        // ===== TOGGLE SWITCHES =====
        attachToggleSwitches: function () {
            const self = this;
            document.querySelectorAll('.toggle-switch').forEach(function (checkbox) {
                const form = checkbox.closest('form');
                if (!form) return;
                
                checkbox.addEventListener('change', function () {
                    // Sync state with server
                    const fieldName = checkbox.getAttribute('name');
                    const isChecked = checkbox.checked;
                    
                    const event = new CustomEvent('admin:toggle-changed', {
                        detail: { field: fieldName, value: isChecked }
                    });
                    document.dispatchEvent(event);
                });
            });
        },

        attachInsuranceClaimActions: function () {
            const self = this;
            document.querySelectorAll('.js-claim-action').forEach(function (button) {
                button.addEventListener('click', function (event) {
                    event.preventDefault();
                    const claimId = button.getAttribute('data-claim-id');
                    const action = button.getAttribute('data-claim-action');
                    const card = button.closest('[data-claim-card]');
                    const form = button.closest('form[data-claim-form]');
                    const noteField = form ? form.querySelector('.js-claim-note') : null;
                    const noteValue = noteField ? noteField.value.trim() : '';
                    const payoutField = form ? form.querySelector('input[name="payout_amount"]') : null;
                    const payoutValue = payoutField ? payoutField.value : '0';
                    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

                    if (!claimId || !action || !form) {
                        return;
                    }

                    button.disabled = true;
                    button.textContent = 'Processing…';

                    fetch('insurance-actions.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            claim_id: claimId,
                            action: action,
                            claim_note: noteValue,
                            payout_amount: payoutValue,
                            csrf_token: csrfToken
                        })
                    }).then(function (response) {
                        return response.json();
                    }).then(function (result) {
                        if (result.success) {
                            if (card) {
                                const statusLabel = card.querySelector('[data-claim-status-display]');
                                if (statusLabel) {
                                    statusLabel.textContent = result.status || 'Updated';
                                }
                            }
                            self.showAlert(result.message || 'Claim workflow updated.', 'success');
                        } else {
                            self.showAlert(result.message || 'Unable to update the claim workflow.', 'error');
                        }
                    }).catch(function () {
                        self.showAlert('The claim update request failed. Please refresh and try again.', 'error');
                    }).finally(function () {
                        button.disabled = false;
                        button.textContent = action === 'approve' ? 'Approve' : 'Reject';
                    });
                });
            });
        },

        // ===== ALERTS & NOTIFICATIONS =====
        showAlert: function (message, type) {
            const container = document.querySelector('[data-alert-container]') || document.body;
            const alertId = 'alert_' + Date.now();
            
            const alert = document.createElement('div');
            alert.id = alertId;
            alert.className = 'mb-6 rounded-2xl border px-4 py-3 text-sm font-semibold animate-slideIn';
            
            const typeClasses = {
                'success': 'border-emerald-200 bg-emerald-50 text-emerald-700',
                'error': 'border-red-200 bg-red-50 text-red-700',
                'warning': 'border-amber-200 bg-amber-50 text-amber-700',
                'info': 'border-blue-200 bg-blue-50 text-blue-700'
            };
            
            alert.className += ' ' + (typeClasses[type] || typeClasses.info);
            alert.innerHTML = '<div class="flex justify-between items-center"><span>' + this.escapeHtml(message) + '</span><button data-alert-close="' + alertId + '" class="text-lg font-bold opacity-60 hover:opacity-100">×</button></div>';
            
            if (container === document.body) {
                container.insertBefore(alert, container.firstChild);
            } else {
                container.insertBefore(alert, container.firstChild);
            }
            
            const closeBtn = alert.querySelector('[data-alert-close]');
            closeBtn.addEventListener('click', function () {
                alert.remove();
            });
            
            setTimeout(function () {
                if (alert.parentElement) {
                    alert.style.opacity = '0';
                    setTimeout(function () {
                        alert.remove();
                    }, 300);
                }
            }, 5000);
        }
    };

    var adminUiRegistry = {
        listeners: [],
        add: function (eventName, handler) {
            this.listeners.push({ eventName: eventName, handler: handler });
            document.addEventListener(eventName, handler);
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        adminApp.init();
        document.body.classList.add('sr-admin-ui-ready');
        document.dispatchEvent(new CustomEvent('smart-rental:admin-ui-ready', { detail: { app: adminApp, registry: adminUiRegistry } }));
    });

    window.SmartRentalAdminApp = adminApp;
    window.SmartRentalAdminUIRegistry = adminUiRegistry;
})();
