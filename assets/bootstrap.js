import { Application } from '@hotwired/stimulus';

// Create the Stimulus application
const application = Application.start();

// Register the instructions controller
import instructionsController from './controllers/instructions_controller';
application.register('instructions', instructionsController);

// Register the flash message controller
import flashMessageController from './controllers/flash_message_controller';
application.register('flash-message', flashMessageController);

// Export for debugging
window.Stimulus = application;

export { application };