import { Controller } from '@hotwired/stimulus';

/**
 * Skills Table Controller
 * Handles test connection, delete operations, and skill requests for integrations
 */
export default class extends Controller {
    static targets = [
        'row',
        'requestForm',
        'skillNameInput',
        'descriptionInput',
        'apiUrlInput',
        'priorityInput',
        'submitButton',
        'skillNameError'
    ];

    /**
     * Test connection to integration
     */
    async testConnection(event) {
        const button = event.currentTarget;
        const type = button.dataset.type;
        const instance = button.dataset.instance;
        const originalContent = button.innerHTML;

        // Disable button and show loading state
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Testing...';

        try {
            const response = await fetch(`/skills/${type}/test`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: `instance=${encodeURIComponent(instance)}`
            });

            const data = await response.json();

            if (data.success) {
                this.showTestModal(
                    'success',
                    'Connection Successful',
                    data.message || 'The connection test was successful. All API endpoints are reachable.',
                    data.details || null,
                    data.suggestion || null
                );
            } else {
                this.showTestModal(
                    'error',
                    'Connection Failed',
                    data.message || 'The connection test failed. Please check your credentials and try again.',
                    data.details || null,
                    data.suggestion || null
                );
            }
        } catch (error) {
            console.error('Test connection error:', error);
            this.showTestModal(
                'error',
                'Test Error',
                'An unexpected error occurred while testing the connection. Please try again later.',
                null,
                null
            );
        } finally {
            // Restore button state
            button.disabled = false;
            button.innerHTML = originalContent;
        }
    }

    /**
     * Delete integration with confirmation
     */
    async deleteIntegration(event) {
        const button = event.currentTarget;
        const instanceId = button.dataset.instanceId;
        const instanceName = button.dataset.instanceName;

        // Confirmation dialog
        const confirmed = confirm(`Are you sure you want to delete "${instanceName}"? This action cannot be undone.`);

        if (!confirmed) {
            return;
        }

        try {
            const response = await fetch(`/skills/delete/${instanceId}`, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                throw new Error('Failed to delete integration');
            }

            // Remove the row from table
            const row = button.closest('tr');
            row.style.transition = 'opacity 0.3s ease';
            row.style.opacity = '0';

            setTimeout(() => {
                row.remove();

                // Check if table is now empty
                const remainingRows = this.rowTargets.length;
                if (remainingRows === 0) {
                    // Reload page to show empty state
                    window.location.reload();
                }
            }, 300);

            this.showToast('success', 'Integration deleted successfully');

        } catch (error) {
            console.error('Error deleting integration:', error);
            this.showToast('error', 'Failed to delete integration');
        }
    }

    /**
     * Show test connection modal
     */
    showTestModal(type, title, message, details = null, suggestion = null) {
        const modal = document.getElementById('testConnectionModal');
        if (!modal) {
            console.error('Test connection modal not found');
            return;
        }

        const modalContentWrapper = document.getElementById('modalContentWrapper');
        const modalIcon = document.getElementById('modalIcon');
        const modalTitle = document.getElementById('modalTitle');
        const modalMessage = document.getElementById('modalMessage');
        const modalDetails = document.getElementById('modalDetails');
        const modalSuggestion = document.getElementById('modalSuggestion');
        const modalCloseBtn = document.getElementById('modalCloseBtn');

        // Update content
        modalTitle.textContent = title;
        modalMessage.textContent = message;
        modalMessage.className = 'modal-message ' + type;

        // Update modal content wrapper with status class for left border accent
        modalContentWrapper.className = 'modal-content status-' + type;

        // Update close button with status class for color matching
        modalCloseBtn.className = 'btn btn-status-' + type;

        // Update details - use classList instead of inline styles
        if (details) {
            modalDetails.textContent = details;
            modalDetails.classList.remove('hidden');
        } else {
            modalDetails.classList.add('hidden');
        }

        // Update suggestion - use classList instead of inline styles
        if (suggestion) {
            modalSuggestion.textContent = suggestion;
            modalSuggestion.classList.remove('hidden');
        } else {
            modalSuggestion.classList.add('hidden');
        }

        // Update icon
        modalIcon.className = 'modal-icon ' + type;
        if (type === 'success') {
            modalIcon.innerHTML = '<i class="fas fa-check-circle"></i>';
        } else {
            modalIcon.innerHTML = '<i class="fas fa-exclamation-circle"></i>';
        }

        // Show modal
        modal.classList.add('show');

        // Focus trap for accessibility
        const closeButton = modal.querySelector('.modal-close');
        if (closeButton) {
            closeButton.focus();
        }
    }

    /**
     * Show toast notification (simple feedback)
     */
    showToast(type, message) {
        // Create toast element if it doesn't exist
        let toast = document.getElementById('toast-notification');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'toast-notification';
            toast.className = 'toast-notification';
            document.body.appendChild(toast);
        }

        // Set content and type
        toast.textContent = message;
        toast.className = `toast-notification toast-${type} show`;

        // Auto-hide after 3 seconds
        setTimeout(() => {
            toast.classList.remove('show');
        }, 3000);
    }

    /**
     * Open skill request modal
     */
    openRequestModal(event) {
        event.preventDefault();
        const modal = document.getElementById('skillRequestModal');
        if (modal) {
            modal.classList.add('show');
            // Focus on skill name input
            if (this.hasSkillNameInputTarget) {
                setTimeout(() => this.skillNameInputTarget.focus(), 100);
            }
        }
    }

    /**
     * Submit skill request
     */
    async submitRequest(event) {
        event.preventDefault();

        // Validate skill name
        const skillName = this.skillNameInputTarget.value.trim();
        if (!skillName) {
            this.skillNameInputTarget.classList.add('is-invalid');
            if (this.hasSkillNameErrorTarget) {
                this.skillNameErrorTarget.textContent = 'Service name is required';
                this.skillNameErrorTarget.style.display = 'block';
            }
            return;
        }

        // Clear validation error
        this.skillNameInputTarget.classList.remove('is-invalid');
        if (this.hasSkillNameErrorTarget) {
            this.skillNameErrorTarget.style.display = 'none';
        }

        // Get form data
        const formData = new FormData();
        formData.append('skillName', skillName);
        formData.append('description', this.descriptionInputTarget.value.trim());
        formData.append('apiDocumentationUrl', this.apiUrlInputTarget.value.trim());
        formData.append('priority', this.priorityInputTarget.value);

        // Disable submit button and show loading state
        const submitButton = this.submitButtonTarget;
        const originalContent = submitButton.innerHTML;
        submitButton.disabled = true;
        submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

        try {
            const response = await fetch('/skills/request', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                // Close modal
                const modal = document.getElementById('skillRequestModal');
                modal.classList.remove('show');

                // Reset form
                this.requestFormTarget.reset();

                // Show success toast
                this.showToast('success', data.message || 'Your skill request has been submitted successfully!');
            } else {
                // Show error toast
                this.showToast('error', data.message || 'Failed to submit skill request. Please try again.');
            }
        } catch (error) {
            console.error('Error submitting skill request:', error);
            this.showToast('error', 'An unexpected error occurred. Please try again later.');
        } finally {
            // Restore button state
            submitButton.disabled = false;
            submitButton.innerHTML = originalContent;
        }
    }
}
