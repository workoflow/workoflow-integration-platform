import { Controller } from '@hotwired/stimulus';

/**
 * Flash Message Controller
 * Handles dismissing flash messages with animation
 */
export default class extends Controller {
    static values = {
        autoDismiss: { type: Boolean, default: true },
        dismissDelay: { type: Number, default: 5000 } // 5 seconds
    }

    connect() {
        // Auto-dismiss if enabled
        if (this.autoDismissValue) {
            this.timeoutId = setTimeout(() => {
                this.close();
            }, this.dismissDelayValue);
        }
    }

    disconnect() {
        // Clear timeout when element is removed
        if (this.timeoutId) {
            clearTimeout(this.timeoutId);
        }
    }

    /**
     * Close button clicked - dismiss the message
     */
    close() {
        // Add dismissing class for animation
        this.element.classList.add('alert-dismissing');

        // Remove element after animation completes
        setTimeout(() => {
            this.element.remove();
        }, 300); // Match the CSS transition duration
    }

    /**
     * Pause auto-dismiss on hover
     */
    pauseAutoDismiss() {
        if (this.timeoutId) {
            clearTimeout(this.timeoutId);
        }
    }

    /**
     * Resume auto-dismiss after hover
     */
    resumeAutoDismiss() {
        if (this.autoDismissValue) {
            this.timeoutId = setTimeout(() => {
                this.close();
            }, this.dismissDelayValue);
        }
    }
}
