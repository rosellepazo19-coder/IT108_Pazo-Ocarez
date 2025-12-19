// Registration Form JavaScript
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('registrationForm');
    const steps = document.querySelectorAll('.step');
    const prevBtn = document.getElementById('prevBtn');
    const nextBtn = document.getElementById('nextBtn');
    const submitBtn = document.getElementById('submitBtn');
    
    let currentStep = 1;
    const totalSteps = steps.length;

    // Initialize
    updateStepDisplay();

    // Next button
    if (nextBtn) {
        nextBtn.addEventListener('click', function() {
            if (validateCurrentStep()) {
                if (currentStep < totalSteps) {
                    currentStep++;
                    updateStepDisplay();
                }
            }
        });
    }

    // Previous button
    if (prevBtn) {
        prevBtn.addEventListener('click', function() {
            if (currentStep > 1) {
                currentStep--;
                updateStepDisplay();
            }
        });
    }

    // Update step display
    function updateStepDisplay() {
        // Hide all steps
        steps.forEach(step => {
            step.classList.remove('active');
        });

        // Show current step
        if (steps[currentStep - 1]) {
            steps[currentStep - 1].classList.add('active');
        }

        // Update step progress indicator
        const stepItems = document.querySelectorAll('.step-item');
        stepItems.forEach((item, index) => {
            const stepNum = index + 1;
            item.classList.remove('active', 'completed');
            if (stepNum === currentStep) {
                item.classList.add('active');
            } else if (stepNum < currentStep) {
                item.classList.add('completed');
            }
        });

        // Update buttons
        if (prevBtn) {
            prevBtn.style.display = currentStep === 1 ? 'none' : 'block';
        }
        
        if (nextBtn) {
            nextBtn.style.display = currentStep === totalSteps ? 'none' : 'block';
        }
        
        if (submitBtn) {
            submitBtn.style.display = currentStep === totalSteps ? 'block' : 'none';
        }
    }

    // Auto-generate ID
    async function generateId() {
        const idField = document.getElementById('idnum');
        if (!idField) return;

        try {
            const res = await fetch('generate_id.php');
            const data = await res.json();
            if (data.idnum) {
                idField.value = data.idnum;
            }
        } catch (err) {
            console.error('Error generating ID:', err);
        }
    }

    // Calculate age from birthday
    function calculateAge() {
        const birthday = document.getElementById('Birthday');
        const age = document.getElementById('Age');
        
        if (birthday && age && birthday.value) {
            const birthDate = new Date(birthday.value);
            const today = new Date();
            let ageValue = today.getFullYear() - birthDate.getFullYear();
            const monthDiff = today.getMonth() - birthDate.getMonth();
            
            if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
                ageValue--;
            }
            
            age.value = ageValue >= 0 ? ageValue : '';
        }
    }

    // Event listeners
    const birthdayField = document.getElementById('Birthday');
    if (birthdayField) {
        birthdayField.addEventListener('change', calculateAge);
        birthdayField.addEventListener('input', calculateAge);
    }

    // Form submission validation
    if (form) {
        form.addEventListener('submit', function(e) {
            // Validate current step first
            if (!validateCurrentStep()) {
                e.preventDefault();
                alert('Please fix the errors in the current step before submitting.');
                return false;
            }

            // Validate all steps before submission
            const originalStep = currentStep;
            let allValid = true;
            let firstInvalidStep = null;

            for (let step = 1; step <= totalSteps; step++) {
                currentStep = step;
                if (!validateCurrentStep()) {
                    allValid = false;
                    if (!firstInvalidStep) {
                        firstInvalidStep = step;
                    }
                }
            }

            // Restore to original step or go to first invalid step
            if (!allValid) {
                currentStep = firstInvalidStep || originalStep;
                updateStepDisplay();
                e.preventDefault();
                alert('Please fix all errors before submitting.');
                return false;
            }

            // Restore original step if all valid
            currentStep = originalStep;
            updateStepDisplay();
        });
    }

    // Password strength checker
    function checkPasswordStrength(password) {
        const requirements = {
            uppercase: /[A-Z]/.test(password),
            lowercase: /[a-z]/.test(password),
            number: /[0-9]/.test(password),
            special: /[@$!%*?&]/.test(password),
            length: password.length >= 8
        };

        // Update requirement indicators (hidden but still used for validation)
        const reqUppercase = document.getElementById('req-uppercase');
        const reqLowercase = document.getElementById('req-lowercase');
        const reqNumber = document.getElementById('req-number');
        const reqSpecial = document.getElementById('req-special');
        const reqLength = document.getElementById('req-length');
        
        if (reqUppercase) reqUppercase.classList.toggle('met', requirements.uppercase);
        if (reqLowercase) reqLowercase.classList.toggle('met', requirements.lowercase);
        if (reqNumber) reqNumber.classList.toggle('met', requirements.number);
        if (reqSpecial) reqSpecial.classList.toggle('met', requirements.special);
        if (reqLength) reqLength.classList.toggle('met', requirements.length);

        // Show warnings only if password has issues
        const passwordField = document.getElementById('password');
        const allMet = Object.values(requirements).every(v => v);
        
        if (password && password.length > 0 && !allMet) {
            // Clear previous errors first
            clearFieldError(passwordField);
            
            // Show specific warning for missing requirements
            let missingReqs = [];
            if (!requirements.uppercase) missingReqs.push('At least one uppercase letter (A-Z)');
            if (!requirements.lowercase) missingReqs.push('At least one lowercase letter (a-z)');
            if (!requirements.number) missingReqs.push('At least one number (0-9)');
            if (!requirements.special) missingReqs.push('At least one special character (@$!%*?&)');
            if (!requirements.length) missingReqs.push('Minimum 8 characters');
            
            if (missingReqs.length > 0) {
                // Create a cleaner error message
                let errorMsg = 'Password must contain:\n• ' + missingReqs.join('\n• ');
                showError(passwordField, errorMsg);
            }
        } else if (password && password.length > 0 && allMet) {
            // Clear error if all requirements are met
            clearFieldError(passwordField);
        }

        // Calculate strength (optional, can be hidden too)
        const metCount = Object.values(requirements).filter(v => v).length;
        const strengthStatus = document.getElementById('password-strength-status');
        
        if (strengthStatus) {
            if (metCount === 0 || password.length === 0) {
                strengthStatus.textContent = '';
                strengthStatus.className = '';
            } else if (metCount <= 2) {
                strengthStatus.textContent = 'Weak';
                strengthStatus.className = 'strength-weak';
            } else if (metCount <= 4) {
                strengthStatus.textContent = 'Medium';
                strengthStatus.className = 'strength-medium';
            } else {
                strengthStatus.textContent = 'Strong';
                strengthStatus.className = 'strength-strong';
            }
        }

        return allMet;
    }

    // Show/Hide password toggle
    const togglePassword = document.getElementById('togglePassword');
    const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
    const passwordField = document.getElementById('password');
    const confirmPasswordField = document.getElementById('confirmPassword');

    if (togglePassword && passwordField) {
        togglePassword.addEventListener('click', function() {
            const type = passwordField.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordField.setAttribute('type', type);
            const eyeIcon = this.querySelector('.eye-icon');
            if (eyeIcon) {
                if (type === 'password') {
                    // Show icon
                    eyeIcon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
                } else {
                    // Hide icon (eye with slash)
                    eyeIcon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
                }
            }
        });
    }

    if (toggleConfirmPassword && confirmPasswordField) {
        toggleConfirmPassword.addEventListener('click', function() {
            const type = confirmPasswordField.getAttribute('type') === 'password' ? 'text' : 'password';
            confirmPasswordField.setAttribute('type', type);
            const eyeIcon = this.querySelector('.eye-icon');
            if (eyeIcon) {
                if (type === 'password') {
                    // Show icon
                    eyeIcon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
                } else {
                    // Hide icon (eye with slash)
                    eyeIcon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
                }
            }
        });
    }

    // Real-time password strength checking
    if (passwordField) {
        passwordField.addEventListener('input', function() {
            if (this.value) {
                checkPasswordStrength(this.value);
            } else {
                // Reset all indicators
                ['req-uppercase', 'req-lowercase', 'req-number', 'req-special', 'req-length'].forEach(id => {
                    document.getElementById(id)?.classList.remove('met');
                });
                const strengthStatus = document.getElementById('password-strength-status');
                if (strengthStatus) {
                    strengthStatus.textContent = '';
                    strengthStatus.className = '';
                }
                // Clear error when field is empty
                clearFieldError(this);
            }
        });

        passwordField.addEventListener('blur', function() {
            if (this.value) {
                checkPasswordStrength(this.value);
            } else {
                clearFieldError(this);
            }
        });
    }

    // Real-time password match checking
    if (confirmPasswordField && passwordField) {
        confirmPasswordField.addEventListener('input', function() {
            if (this.value && passwordField.value) {
                if (this.value !== passwordField.value) {
                    showError(this, 'Passwords do not match');
                } else {
                    clearFieldError(this);
                }
            }
        });
    }

    // Generate ID on load
    generateId();

    // Validation Functions
    function validateCurrentStep() {
        clearErrors();
        let isValid = true;
        const currentStepElement = document.querySelector(`.step[data-step="${currentStep}"]`);

        if (!currentStepElement) return false;

        // Step 1: Personal Information
        if (currentStep === 1) {
            const requiredFields = ['idnum', 'Fname', 'Lname', 'sex', 'Birthday', 'Age', 'role'];
            requiredFields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (field && !field.value.trim()) {
                    showError(field, 'This field is required');
                    isValid = false;
                }
            });

            // Validate names (First letter capital, no all small/capital, no numbers)
            const nameFields = [
                { id: 'Fname', label: 'First Name' },
                { id: 'Mname', label: 'Middle Name' },
                { id: 'Lname', label: 'Last Name' }
            ];
            
            nameFields.forEach(({ id, label }) => {
                const field = document.getElementById(id);
                if (field && field.value.trim()) {
                    const value = field.value.trim();
                    
                    // Check for numbers
                    if (/\d/.test(value)) {
                        showError(field, label + ' cannot contain numbers');
                        isValid = false;
                    }
                    // Check for special characters (only allow letters, spaces, and hyphen)
                    else if (/[^a-zA-Z\-\s]/.test(value)) {
                        showError(field, label + ' can only contain letters, spaces, and hyphen');
                        isValid = false;
                    }
                    // Check if first letter is capital
                    else if (!/^[A-Z]/.test(value)) {
                        showError(field, label + ' must start with a capital letter');
                        isValid = false;
                    }
                    // Check if all letters are lowercase
                    else {
                        const lettersOnly = value.replace(/[^A-Za-z]/g, '');
                        if (lettersOnly && lettersOnly === lettersOnly.toLowerCase()) {
                            showError(field, label + ' cannot be all small letters');
                            isValid = false;
                        }
                        // Check if all letters are uppercase
                        else if (lettersOnly && lettersOnly === lettersOnly.toUpperCase()) {
                            showError(field, label + ' cannot be all capital letters');
                            isValid = false;
                        }
                    }
                }
            });

            // Validate age
            const age = document.getElementById('Age');
            if (age && age.value) {
                const ageNum = parseInt(age.value);
                if (isNaN(ageNum) || ageNum < 18) {
                    showError(age, 'You must be at least 18 years old');
                    isValid = false;
                }
            }
        }

        // Step 2: Contact Information
        else if (currentStep === 2) {
            const email = document.getElementById('mail');
            const mobile = document.getElementById('mobile');
            const zipCode = document.getElementById('ZipCode');

            // Email validation - must be @gmail.com (no numbers in domain)
            if (email && email.value) {
                const emailValue = email.value.trim();
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailValue)) {
                    showError(email, 'Please enter a valid email address');
                    isValid = false;
                } else if (!emailValue.toLowerCase().endsWith('@gmail.com')) {
                    showError(email, 'Email must be @gmail.com');
                    isValid = false;
                } else if (/@gmail\d+\.com/i.test(emailValue)) {
                    showError(email, 'Email cannot have numbers in @gmail.com (e.g., @gmail1.com is not allowed)');
                    isValid = false;
                }
            } else {
                showError(email, 'Email is required');
                isValid = false;
            }

            // Mobile validation
            if (mobile && mobile.value) {
                if (!mobile.value.startsWith('09')) {
                    showError(mobile, 'Mobile number must start with "09"');
                    isValid = false;
                } else if (!/^[0-9]{11}$/.test(mobile.value)) {
                    showError(mobile, 'Mobile number must be exactly 11 digits');
                    isValid = false;
                }
            } else {
                showError(mobile, 'Mobile number is required');
                isValid = false;
            }

            // Zip code validation
            if (zipCode && zipCode.value) {
                if (/[a-zA-Z]/.test(zipCode.value)) {
                    showError(zipCode, 'Zip code must not contain letters');
                    isValid = false;
                } else if (!/^[0-9]{4,5}$/.test(zipCode.value)) {
                    showError(zipCode, 'Zip code must be 4 or 5 digits');
                    isValid = false;
                }
            } else {
                showError(zipCode, 'Zip code is required');
                isValid = false;
            }

            // Address fields validation (First letter capital, no all small/capital)
            const addressFields = [
                { id: 'Street', label: 'Street' },
                { id: 'Barangay', label: 'Barangay' },
                { id: 'City', label: 'City' },
                { id: 'Province', label: 'Province' },
                { id: 'Country', label: 'Country' }
            ];
            
            addressFields.forEach(({ id, label }) => {
                const field = document.getElementById(id);
                if (!field || !field.value.trim()) {
                    showError(field, label + ' is required');
                    isValid = false;
                } else {
                    const value = field.value.trim();
                    const lettersOnly = value.replace(/[^A-Za-z]/g, '');
                    
                    // Check if first letter is capital
                    if (lettersOnly && !/^[A-Z]/.test(value)) {
                        showError(field, label + ' must start with a capital letter');
                        isValid = false;
                    }
                    // Check if all letters are lowercase
                    else if (lettersOnly && lettersOnly === lettersOnly.toLowerCase() && lettersOnly.length > 0) {
                        showError(field, label + ' cannot be all small letters');
                        isValid = false;
                    }
                    // Check if all letters are uppercase
                    else if (lettersOnly && lettersOnly === lettersOnly.toUpperCase() && lettersOnly.length > 0) {
                        showError(field, label + ' cannot be all capital letters');
                        isValid = false;
                    }
                }
            });
        }

        // Step 3: Account Security
        else if (currentStep === 3) {
            const password = document.getElementById('password');
            const confirmPassword = document.getElementById('confirmPassword');

            if (!password || !password.value) {
                showError(password, 'Password is required');
                isValid = false;
            } else {
                // Check password strength
                const pwd = password.value;
                const hasUpper = /[A-Z]/.test(pwd);
                const hasLower = /[a-z]/.test(pwd);
                const hasNumber = /[0-9]/.test(pwd);
                const hasSpecial = /[@$!%*?&]/.test(pwd);
                const hasLength = pwd.length >= 8;

                if (!hasUpper) {
                    showError(password, 'Password must contain at least one uppercase letter');
                    isValid = false;
                } else if (!hasLower) {
                    showError(password, 'Password must contain at least one lowercase letter');
                    isValid = false;
                } else if (!hasNumber) {
                    showError(password, 'Password must contain at least one number');
                    isValid = false;
                } else if (!hasSpecial) {
                    showError(password, 'Password must contain at least one special character (@$!%*?&)');
                    isValid = false;
                } else if (!hasLength) {
                    showError(password, 'Password must be at least 8 characters long');
                    isValid = false;
                }
            }

            if (!confirmPassword || !confirmPassword.value) {
                showError(confirmPassword, 'Please confirm your password');
                isValid = false;
            } else if (password && password.value !== confirmPassword.value) {
                showError(confirmPassword, 'Passwords do not match');
                isValid = false;
            }
        }

        // Step 4: Security Questions
        else if (currentStep === 4) {
            const securityQuestions = ['secQ1', 'secQ2', 'secQ3'];
            const securityAnswers = ['secA1', 'secA2', 'secA3'];
            const selectedQuestions = [];

            securityQuestions.forEach((qId, index) => {
                const questionSelect = document.getElementById(qId);
                const answerInput = document.getElementById(securityAnswers[index]);

                // Validate question selection
                if (!questionSelect || !questionSelect.value) {
                    showError(questionSelect, 'Please select a security question');
                    isValid = false;
                } else if (selectedQuestions.includes(questionSelect.value)) {
                    showError(questionSelect, 'Please select unique security questions');
                    isValid = false;
                } else {
                    selectedQuestions.push(questionSelect.value);
                }

                // Validate answer
                if (!answerInput || !answerInput.value.trim()) {
                    showError(answerInput, 'Please provide an answer');
                    isValid = false;
                } else {
                    const answer = answerInput.value.trim();
                    
                    // Minimum length
                    if (answer.length < 3) {
                        showError(answerInput, 'Security answers must be at least 3 characters');
                        isValid = false;
                    }
                    
                    // Check if all capital letters
                    const lettersOnly = answer.replace(/[^A-Za-z]/g, '');
                    if (lettersOnly && lettersOnly === lettersOnly.toUpperCase() && lettersOnly.length > 0) {
                        showError(answerInput, 'Security answer cannot be all capital letters');
                        isValid = false;
                    }
                }
            });
        }

        if (!isValid) {
            // Scroll to first error
            const firstError = currentStepElement.querySelector('.field-error');
            if (firstError) {
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        return isValid;
    }

    // Show error message
    function showError(field, message) {
        if (!field) return;

        // Remove ALL existing errors first to ensure only one warning box
        clearFieldError(field);

        // Add error styling
        field.style.borderColor = '#e74c3c';
        field.style.backgroundColor = '#fdf2f2';

        // Find the correct parent (form-field)
        let parent = field.parentNode;
        if (parent && parent.classList.contains('password-input-wrapper')) {
            parent = parent.parentNode;
        }
        
        // Check if error already exists in parent, remove it first
        if (parent && parent.classList.contains('form-field')) {
            const existingErrors = parent.querySelectorAll('.field-error');
            existingErrors.forEach(err => err.remove());
        }

        // Create error message
        const errorDiv = document.createElement('div');
        errorDiv.className = 'field-error';
        
        // Check if message contains newlines (for password requirements)
        if (message.includes('\n•')) {
            // Format as a list for password requirements
            const parts = message.split('\n');
            const title = parts[0];
            const items = parts.slice(1).filter(p => p.trim());
            
            errorDiv.innerHTML = '<strong>' + title + '</strong>';
            if (items.length > 0) {
                const ul = document.createElement('ul');
                items.forEach(item => {
                    const li = document.createElement('li');
                    li.textContent = item.replace('• ', '');
                    ul.appendChild(li);
                });
                errorDiv.appendChild(ul);
            }
        } else {
            errorDiv.textContent = message;
        }
        
        errorDiv.style.color = '#e74c3c';
        errorDiv.style.fontSize = '0.8rem';
        errorDiv.style.marginTop = '0.25rem';

        // Insert error message - ensure we're appending to form-field
        if (parent && parent.classList.contains('form-field')) {
            parent.appendChild(errorDiv);
        } else {
            // Fallback to original behavior
            field.parentNode.appendChild(errorDiv);
        }
    }

    // Clear error for a field
    function clearFieldError(field) {
        if (!field) return;
        field.style.borderColor = '';
        field.style.backgroundColor = '';
        
        // Remove error from password-input-wrapper if exists
        if (field.parentNode && field.parentNode.classList.contains('password-input-wrapper')) {
            const errorInWrapper = field.parentNode.querySelector('.field-error');
            if (errorInWrapper) errorInWrapper.remove();
        }
        
        // Remove error from form-field parent
        let parent = field.parentNode;
        if (parent && parent.classList.contains('password-input-wrapper')) {
            parent = parent.parentNode;
        }
        if (parent && parent.classList.contains('form-field')) {
            const errors = parent.querySelectorAll('.field-error');
            errors.forEach(err => err.remove());
        } else {
            // Fallback: remove from any parent
            const error = field.parentNode.querySelector('.field-error');
            if (error) error.remove();
        }
    }

    // Clear all errors
    function clearErrors() {
        document.querySelectorAll('.field-error').forEach(error => error.remove());
        document.querySelectorAll('input, select').forEach(field => {
            field.style.borderColor = '';
            field.style.backgroundColor = '';
        });
    }

    // Real-time validation for email
    const emailField = document.getElementById('mail');
    if (emailField) {
        emailField.addEventListener('blur', function() {
            if (this.value.trim()) {
                const emailValue = this.value.trim();
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailValue)) {
                    showError(this, 'Please enter a valid email address');
                } else if (!emailValue.toLowerCase().endsWith('@gmail.com')) {
                    showError(this, 'Email must be @gmail.com');
                } else if (/@gmail\d+\.com/i.test(emailValue)) {
                    showError(this, 'Email cannot have numbers in @gmail.com (e.g., @gmail1.com is not allowed)');
                } else {
                    clearFieldError(this);
                }
            }
        });

        emailField.addEventListener('input', function() {
            if (this.value.trim()) {
                const emailValue = this.value.trim();
                if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailValue) && 
                    emailValue.toLowerCase().endsWith('@gmail.com') &&
                    !/@gmail\d+\.com/i.test(emailValue)) {
                    clearFieldError(this);
                }
            }
        });
    }

    // Real-time validation for address fields
    ['Street', 'Barangay', 'City', 'Province', 'Country'].forEach(fieldId => {
        const addressField = document.getElementById(fieldId);
        if (addressField) {
            const fieldLabel = fieldId;
            
            addressField.addEventListener('blur', function() {
                if (this.value.trim()) {
                    const value = this.value.trim();
                    const lettersOnly = value.replace(/[^A-Za-z]/g, '');
                    
                    if (lettersOnly && !/^[A-Z]/.test(value)) {
                        showError(this, fieldLabel + ' must start with a capital letter');
                    } else if (lettersOnly && lettersOnly === lettersOnly.toLowerCase() && lettersOnly.length > 0) {
                        showError(this, fieldLabel + ' cannot be all small letters');
                    } else if (lettersOnly && lettersOnly === lettersOnly.toUpperCase() && lettersOnly.length > 0) {
                        showError(this, fieldLabel + ' cannot be all capital letters');
                    } else {
                        clearFieldError(this);
                    }
                }
            });

            addressField.addEventListener('input', function() {
                if (this.value.trim()) {
                    const value = this.value.trim();
                    const lettersOnly = value.replace(/[^A-Za-z]/g, '');
                    
                    // Clear error if valid
                    if ((!lettersOnly || /^[A-Z]/.test(value)) &&
                        !(lettersOnly && lettersOnly === lettersOnly.toLowerCase() && lettersOnly.length > 0) &&
                        !(lettersOnly && lettersOnly === lettersOnly.toUpperCase() && lettersOnly.length > 0)) {
                        clearFieldError(this);
                    }
                }
            });
        }
    });

    // Real-time validation for name fields
    ['Fname', 'Mname', 'Lname'].forEach(fieldId => {
        const nameField = document.getElementById(fieldId);
        if (nameField) {
            const fieldLabel = fieldId === 'Fname' ? 'First Name' : 
                              fieldId === 'Mname' ? 'Middle Name' : 'Last Name';
            
            nameField.addEventListener('blur', function() {
                if (this.value.trim()) {
                    const value = this.value.trim();
                    
                    if (/\d/.test(value)) {
                        showError(this, fieldLabel + ' cannot contain numbers');
                    } else if (/[^a-zA-Z\-\s]/.test(value)) {
                        showError(this, fieldLabel + ' can only contain letters, spaces, and hyphen');
                    } else if (!/^[A-Z]/.test(value)) {
                        showError(this, fieldLabel + ' must start with a capital letter');
                    } else {
                        const lettersOnly = value.replace(/[^A-Za-z]/g, '');
                        if (lettersOnly && lettersOnly === lettersOnly.toLowerCase()) {
                            showError(this, fieldLabel + ' cannot be all small letters');
                        } else if (lettersOnly && lettersOnly === lettersOnly.toUpperCase()) {
                            showError(this, fieldLabel + ' cannot be all capital letters');
                        } else {
                            clearFieldError(this);
                        }
                    }
                }
            });

            nameField.addEventListener('input', function() {
                if (this.value.trim()) {
                    const value = this.value.trim();
                    const lettersOnly = value.replace(/[^A-Za-z]/g, '');
                    
                    // Clear error if valid
                    if (!/\d/.test(value) && 
                        !/[^a-zA-Z\-\s]/.test(value) && 
                        /^[A-Z]/.test(value) &&
                        !(lettersOnly && lettersOnly === lettersOnly.toLowerCase()) &&
                        !(lettersOnly && lettersOnly === lettersOnly.toUpperCase())) {
                        clearFieldError(this);
                    }
                }
            });
        }
    });

    // Real-time validation for security answers
    ['secA1', 'secA2', 'secA3'].forEach(answerId => {
        const answerField = document.getElementById(answerId);
        if (answerField) {
            answerField.addEventListener('blur', function() {
                if (this.value.trim()) {
                    const answer = this.value.trim();
                    const lettersOnly = answer.replace(/[^A-Za-z]/g, '');
                    
                    if (lettersOnly && lettersOnly === lettersOnly.toUpperCase() && lettersOnly.length > 0) {
                        showError(this, 'Security answer cannot be all capital letters');
                    } else {
                        clearFieldError(this);
                    }
                }
            });

            answerField.addEventListener('input', function() {
                if (this.value.trim()) {
                    const answer = this.value.trim();
                    const lettersOnly = answer.replace(/[^A-Za-z]/g, '');
                    
                    if (!(lettersOnly && lettersOnly === lettersOnly.toUpperCase() && lettersOnly.length > 0)) {
                        clearFieldError(this);
                    }
                }
            });
        }
    });
});

