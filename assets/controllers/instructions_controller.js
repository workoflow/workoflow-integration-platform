import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['webhookType', 'webhookUrl', 'workflowColumn', 'reactContainer'];

    connect() {
        console.log('Instructions controller connected');
        this.reactFlowRoot = null;

        // Check if we should initialize ReactFlow on page load
        if (this.webhookTypeTarget.value === 'N8N' && this.webhookUrlTarget.value) {
            this.showWorkflowVisualization();
        }
    }

    disconnect() {
        // Clean up React component when controller disconnects
        if (this.reactFlowRoot) {
            this.reactFlowRoot.unmount();
            this.reactFlowRoot = null;
        }
    }

    // Called when webhook type changes
    onWebhookTypeChange(event) {
        const webhookType = event.target.value;

        if (webhookType === 'N8N') {
            this.showWorkflowVisualization();
        } else {
            this.hideWorkflowVisualization();
        }
    }

    // Called when webhook URL changes (with debouncing)
    onWebhookUrlChange(event) {
        // Clear existing timeout
        if (this.urlChangeTimeout) {
            clearTimeout(this.urlChangeTimeout);
        }

        // Debounce the URL change to avoid too many API calls
        this.urlChangeTimeout = setTimeout(() => {
            if (this.webhookTypeTarget.value === 'N8N' && event.target.value) {
                this.refreshWorkflowVisualization();
            }
        }, 1000); // Wait 1 second after user stops typing
    }

    showWorkflowVisualization() {
        // Show the workflow column
        this.workflowColumnTarget.classList.remove('hidden');

        // Initialize ReactFlow if not already done
        if (!this.reactFlowRoot && this.webhookUrlTarget.value) {
            this.initializeReactFlow();
        }
    }

    hideWorkflowVisualization() {
        // Hide the workflow column
        this.workflowColumnTarget.classList.add('hidden');

        // Clean up React component
        if (this.reactFlowRoot) {
            this.reactFlowRoot.unmount();
            this.reactFlowRoot = null;
        }
    }

    async initializeReactFlow() {
        try {
            // Get the organization ID from the React container
            const orgId = this.reactContainerTarget.dataset.orgId;

            // Dynamically import the React component
            const module = await import('../react/N8nWorkflowViewer.jsx');

            // Check if the initialization function is available
            if (window.initN8nWorkflowViewer) {
                this.reactFlowRoot = window.initN8nWorkflowViewer('reactflow-container', orgId);
                console.log('ReactFlow initialized successfully');
            } else {
                console.error('N8nWorkflowViewer initialization function not found');
                this.showError('Failed to initialize workflow viewer');
            }
        } catch (error) {
            console.error('Error initializing ReactFlow:', error);
            this.showError('Error loading workflow viewer');
        }
    }

    refreshWorkflowVisualization() {
        // If ReactFlow is already initialized, trigger a refresh
        if (this.reactFlowRoot) {
            // Unmount and remount to refresh the data
            this.reactFlowRoot.unmount();
            this.reactFlowRoot = null;
            this.initializeReactFlow();
        } else if (this.webhookTypeTarget.value === 'N8N') {
            this.initializeReactFlow();
        }
    }

    showError(message) {
        // Update the placeholder with error message
        const placeholder = this.reactContainerTarget.querySelector('.workflow-placeholder span');
        if (placeholder) {
            placeholder.textContent = message;
        }
    }

    // Handle form submission via AJAX (optional enhancement)
    async submitForm(event) {
        event.preventDefault();

        const form = event.target;
        const formData = new FormData(form);

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: formData,
            });

            if (response.ok) {
                // Show success message
                this.showFlashMessage('Settings saved successfully', 'success');

                // Refresh workflow if N8N and URL changed
                if (this.webhookTypeTarget.value === 'N8N') {
                    this.refreshWorkflowVisualization();
                }
            } else {
                this.showFlashMessage('Failed to save settings', 'error');
            }
        } catch (error) {
            console.error('Error submitting form:', error);
            this.showFlashMessage('An error occurred while saving', 'error');
        }
    }

    showFlashMessage(message, type) {
        // Create and show a flash message
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type}`;
        alertDiv.textContent = message;
        alertDiv.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            background-color: ${type === 'success' ? '#10b981' : '#ef4444'};
            color: white;
            border-radius: 6px;
            z-index: 1000;
            animation: slideIn 0.3s ease-out;
        `;

        document.body.appendChild(alertDiv);

        // Remove after 3 seconds
        setTimeout(() => {
            alertDiv.remove();
        }, 3000);
    }
}