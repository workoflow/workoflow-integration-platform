import { Controller } from '@hotwired/stimulus';

/**
 * Integration Dropdown Controller
 * Handles the "Add New Integration" dropdown menu behavior
 */
export default class extends Controller {
    static targets = ['button', 'menu'];

    connect() {
        // Bind click outside handler
        this.boundHandleClickOutside = this.handleClickOutside.bind(this);
        document.addEventListener('click', this.boundHandleClickOutside);

        // Bind ESC key handler
        this.boundHandleEscape = this.handleEscape.bind(this);
        document.addEventListener('keydown', this.boundHandleEscape);
    }

    disconnect() {
        // Clean up event listeners
        document.removeEventListener('click', this.boundHandleClickOutside);
        document.removeEventListener('keydown', this.boundHandleEscape);
    }

    /**
     * Toggle dropdown menu visibility
     */
    toggle(event) {
        event.stopPropagation();

        if (this.menuTarget.style.display === 'none' || !this.menuTarget.style.display) {
            this.open();
        } else {
            this.close();
        }
    }

    /**
     * Open dropdown menu
     */
    open() {
        this.menuTarget.style.display = 'block';
        this.buttonTarget.classList.add('active');

        // Set ARIA attributes
        this.buttonTarget.setAttribute('aria-expanded', 'true');

        // Focus first menu item for keyboard navigation
        const firstLink = this.menuTarget.querySelector('a');
        if (firstLink) {
            setTimeout(() => firstLink.focus(), 0);
        }
    }

    /**
     * Close dropdown menu
     */
    close() {
        this.menuTarget.style.display = 'none';
        this.buttonTarget.classList.remove('active');

        // Set ARIA attributes
        this.buttonTarget.setAttribute('aria-expanded', 'false');
    }

    /**
     * Handle clicks outside the dropdown
     */
    handleClickOutside(event) {
        if (!this.element.contains(event.target)) {
            this.close();
        }
    }

    /**
     * Handle ESC key to close dropdown
     */
    handleEscape(event) {
        if (event.key === 'Escape' && this.menuTarget.style.display !== 'none') {
            this.close();
            this.buttonTarget.focus();
        }
    }
}
