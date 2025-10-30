import { Controller } from '@hotwired/stimulus';

/**
 * Flash Message Controller
 * Handles manual dismissal of flash messages
 */
export default class extends Controller {
    /**
     * Close button clicked - dismiss the message immediately
     */
    close() {
        this.element.remove();
    }
}
