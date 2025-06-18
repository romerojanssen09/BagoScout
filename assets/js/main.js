/**
 * Main JavaScript file for BagoScout
 */

// Wait for DOM to be loaded
document.addEventListener('DOMContentLoaded', function() {
    // Initialize UI components
    initUI();
});

/**
 * Initialize UI components
 */
function initUI() {
    // Add active class to current nav item
    const currentPage = window.location.pathname.split('/').pop();
    const navLinks = document.querySelectorAll('nav ul li a');
    
    navLinks.forEach(link => {
        const linkPage = link.getAttribute('href');
        if (linkPage === currentPage || 
            (currentPage === '' && linkPage === 'index.php')) {
            link.parentElement.classList.add('active');
        }
    });
    
    // Initialize form validation
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(event) {
            if (!validateForm(form)) {
                event.preventDefault();
            }
        });
    });
}

/**
 * Validate form inputs
 * 
 * @param {HTMLFormElement} form The form to validate
 * @return {boolean} True if valid, false otherwise
 */
function validateForm(form) {
    let isValid = true;
    
    // Check required fields
    const requiredFields = form.querySelectorAll('[required]');
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            showError(field, 'This field is required');
            isValid = false;
        } else {
            clearError(field);
        }
    });
    
    // Check email fields
    const emailFields = form.querySelectorAll('input[type="email"]');
    emailFields.forEach(field => {
        if (field.value.trim() && !isValidEmail(field.value)) {
            showError(field, 'Please enter a valid email address');
            isValid = false;
        }
    });
    
    // Check password fields
    const passwordField = form.querySelector('input[name="password"]');
    const confirmPasswordField = form.querySelector('input[name="confirm_password"]');
    
    if (passwordField && confirmPasswordField) {
        if (passwordField.value !== confirmPasswordField.value) {
            showError(confirmPasswordField, 'Passwords do not match');
            isValid = false;
        }
    }
    
    return isValid;
}

/**
 * Show error message for a form field
 * 
 * @param {HTMLElement} field The field with error
 * @param {string} message Error message
 */
function showError(field, message) {
    // Clear any existing error
    clearError(field);
    
    // Create error message element
    const errorDiv = document.createElement('div');
    errorDiv.className = 'error-message';
    errorDiv.textContent = message;
    
    // Insert error message after the field
    field.parentNode.insertBefore(errorDiv, field.nextSibling);
    
    // Add error class to field
    field.classList.add('error');
}

/**
 * Clear error message for a form field
 * 
 * @param {HTMLElement} field The field to clear error
 */
function clearError(field) {
    // Remove error class
    field.classList.remove('error');
    
    // Remove error message if exists
    const errorDiv = field.parentNode.querySelector('.error-message');
    if (errorDiv) {
        errorDiv.parentNode.removeChild(errorDiv);
    }
}

/**
 * Check if a string is a valid email
 * 
 * @param {string} email The email to validate
 * @return {boolean} True if valid, false otherwise
 */
function isValidEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
} 