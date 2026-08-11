
/* GemVerify Backend Integration Script for Admin Portal */
(function() {
    const API_BASE = '/gemverify/api';
    let token = localStorage.getItem('gv_admin_token');

    // Helper for API requests
    async function apiRequest(endpoint, method = 'GET', data = null, isFormData = false) {
        const headers = {};
        if (token) {
            headers['Authorization'] = 'Bearer ' + token;
        }
        if (!isFormData && data && method !== 'GET') {
            headers['Content-Type'] = 'application/json';
        }

        const options = {
            method: method,
            headers: headers
        };

        if (data) {
            options.body = isFormData ? data : JSON.stringify(data);
        }

        try {
            const res = await fetch(API_BASE + endpoint, options);
            const json = await res.json();
            if (!res.ok) {
                if (res.status === 401 && !endpoint.includes('/admin/auth/login')) {
                    localStorage.removeItem('gv_admin_token');
                    showLoginOverlay();
                }
                throw new Error(json.message || 'API Error');
            }
            return json;
        } catch (err) {
            console.error('API Error:', err);
            throw err;
        }
    }

    // Expose API utility globally for Admin Portal
    window.gvAdminApi = {
        login: async (email, password) => {
            const res = await apiRequest('/admin/auth/login', 'POST', { email, password });
            if (res.success && res.data.token) {
                token = res.data.token;
                localStorage.setItem('gv_admin_token', token);
            }
            return res;
        },
        getStats: () => apiRequest('/admin/stats'),
        getRequests: (params = '') => apiRequest('/admin/requests' + (params ? '?' + params : '')),
        getRequestDetail: (ref) => apiRequest('/admin/requests/' + ref),
        updateStatus: (ref, status, notes) => apiRequest('/admin/requests/' + ref + '/status', 'PATCH', { status, notes }),
        uploadResult: (ref, formData) => apiRequest('/admin/requests/' + ref + '/result', 'POST', formData, true),
        processRefund: (ref, reason) => apiRequest('/admin/requests/' + ref + '/refund', 'POST', { reason }),
        addNote: (ref, note) => apiRequest('/admin/requests/' + ref + '/notes', 'POST', { note }),
        requestInfo: (ref, message) => apiRequest('/admin/requests/' + ref + '/info-request', 'POST', { message })
    };

    function showLoginOverlay() {
        console.log('Admin auth required');
    }
})();
