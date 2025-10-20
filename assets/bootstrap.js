import { Application } from '@hotwired/stimulus';

// Create the Stimulus application
const application = Application.start();

// Register the instructions controller
import instructionsController from './controllers/instructions_controller';
application.register('instructions', instructionsController);

// Export for debugging
window.Stimulus = application;

export { application };