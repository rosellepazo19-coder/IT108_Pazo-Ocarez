// Multi-step Registration Form JavaScript
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('multiStepForm');
    if (!form) {
        console.error('Form not found!');
        return;
    }
    
    const stepContents = document.querySelectorAll('.step-content');
    const stepItems = document.querySelectorAll('.step-item');
    const prevBtn = document.getElementById('prevBtn');
    const nextBtn = document.getElementById('nextBtn');
    const submitBtn = document.getElementById('submitBtn');
    
    console.log('=== INITIALIZATION ===');
    console.log('Step contents found:', stepContents.length);
    stepContents.forEach((content, index) => {
        console.log(`Step ${index + 1}: data-step="${content.getAttribute('data-step')}"`);
    });
    console.log('Step items found:', stepItems.length);
    console.log('Buttons found:', { prevBtn: !!prevBtn, nextBtn: !!nextBtn, submitBtn: !!submitBtn });
    
    if (!prevBtn || !nextBtn || !submitBtn) {
        console.error('Navigation buttons not found!', { prevBtn, nextBtn, submitBtn });
        return;
    }
    
    if (stepContents.length !== 4) {
        console.error('ERROR: Expected 4 step contents, found', stepContents.length);
    }
    
    let currentStep = 1;
    const totalSteps = 4;

    // Initialize form - ensure step 1 shows only Next button
    updateStepDisplay();
    
    // Force set initial button states for Step 1
    // Step 1: Only Next button visible
    if (prevBtn) {
        prevBtn.classList.add('hidden');
        prevBtn.style.cssText = 'display: none !important; visibility: hidden !important; opacity: 0 !important; pointer-events: none !important; width: 0 !important; height: 0 !important; padding: 0 !important; margin: 0 !important;';
    }
    
    if (submitBtn) {
        submitBtn.classList.add('hidden');
        submitBtn.style.cssText = 'display: none !important; visibility: hidden !important; opacity: 0 !important; pointer-events: none !important; width: 0 !important; height: 0 !important; padding: 0 !important; margin: 0 !important;';
    }
    
    if (nextBtn) {
        nextBtn.classList.remove('hidden');
        nextBtn.style.cssText = 'display: block !important; visibility: visible !important; opacity: 1 !important; pointer-events: auto !important;';
        nextBtn.textContent = 'Next';
    }
    
    // Then update button visibility (this will ensure everything is correct)
    updateButtonVisibility();
    
    // Debug: Log button states
    console.log('Buttons initialized:', {
        prevBtn: prevBtn ? 'found' : 'not found',
        nextBtn: nextBtn ? 'found' : 'not found',
        submitBtn: submitBtn ? 'found' : 'not found',
        currentStep: currentStep
    });

    // Next button event listener - SIMPLE AND DIRECT
    function handleNextClick(e) {
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        console.log('=== NEXT BUTTON CLICKED ===');
        console.log('Current step:', currentStep);
        console.log('Total steps:', totalSteps);
        
        // Try validation first
        let isValid = false;
        try {
            isValid = validateCurrentStep();
            console.log('Validation result:', isValid);
        } catch (err) {
            console.error('Validation error:', err);
            isValid = false;
        }
        
        // Always allow progression - validation is just for user feedback
        if (true) {
            if (currentStep < totalSteps) {
                currentStep++;
                console.log('Moving to step:', currentStep);
                console.log('Total steps available:', stepContents.length);
                
                // Double-check step 4 exists
                if (currentStep === 4) {
                    const step4Content = document.querySelector('.step-content[data-step="4"]');
                    console.log('Step 4 content exists:', step4Content ? 'YES' : 'NO');
                    if (!step4Content) {
                        console.error('ERROR: Step 4 content not found in DOM!');
                    }
                }
                
                updateStepDisplay();
                updateButtonVisibility();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } else {
                console.log('Already at last step, cannot go further');
            }
            // Note: On step 4, the Register button handles submission, not Next button
        } else {
            console.log('Validation failed - showing errors');
            const firstError = document.querySelector('.field-error');
            if (firstError) {
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
        
        return false;
    }
    
    if (nextBtn) {
        // Try multiple methods to ensure it works
        nextBtn.onclick = handleNextClick;
        nextBtn.addEventListener('click', handleNextClick, false);
        nextBtn.addEventListener('click', function(e) { e.preventDefault(); }, true);
        
        console.log('Next button handler attached');
    } else {
        console.error('Next button not found!');
    }

    // Previous/Back button event listener
    function handlePreviousClick(e) {
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        console.log('=== BACK BUTTON CLICKED ===');
        console.log('Current step:', currentStep);
        
        if (currentStep > 1) {
            currentStep--;
            console.log('Moving back to step:', currentStep);
            updateStepDisplay();
            updateButtonVisibility();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } else {
            console.log('Already on step 1, cannot go back');
        }
        
        return false;
    }
    
    if (prevBtn) {
        // Use multiple methods to ensure it works
        prevBtn.onclick = handlePreviousClick;
        prevBtn.addEventListener('click', handlePreviousClick, false);
        prevBtn.addEventListener('click', function(e) { e.preventDefault(); }, true);
        console.log('Back button handler attached');
    } else {
        console.error('Back button not found!');
    }
    
    // Register button click handler - only works on step 4
    function handleRegisterClick(e) {
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        console.log('=== REGISTER BUTTON CLICKED ===');
        console.log('Current step:', currentStep);
        
        if (currentStep !== totalSteps) {
            // Move to last step if not there
            currentStep = totalSteps;
            updateStepDisplay();
            updateButtonVisibility();
            window.scrollTo({ top: 0, behavior: 'smooth' });
            alert('Please complete all steps before registering.');
            return false;
        }
        
        // Validate before submitting
        const stepOk = validateCurrentStep();
        const fullOk = (typeof window.validateForm === 'function') ? window.validateForm() : true;
        
        if (stepOk && fullOk) {
            console.log('Submitting form...');
            form.submit();
        } else {
            alert('Please fix the validation errors before submitting.');
        }
        
        return false;
    }
    
    if (submitBtn) {
        submitBtn.onclick = handleRegisterClick;
        submitBtn.addEventListener('click', handleRegisterClick, false);
        console.log('Register button handler attached');
    }

    // Step indicator click event listeners
    stepItems.forEach((item) => {
        item.addEventListener('click', function() {
            const stepNumber = parseInt(item.dataset.step);
            if (stepNumber <= currentStep || isStepCompleted(stepNumber)) {
                currentStep = stepNumber;
                updateStepDisplay();
                updateButtonVisibility();
            }
        });
    });

    // Update step display
    function updateStepDisplay() {
        console.log('=== UPDATING STEP DISPLAY ===');
        console.log('Current step:', currentStep);
        console.log('Total step contents found:', stepContents.length);
        
        // Remove active from all steps
        stepContents.forEach((content, index) => {
            const stepNum = content.getAttribute('data-step');
            console.log(`Step content ${index + 1}: data-step="${stepNum}", has active: ${content.classList.contains('active')}`);
            content.classList.remove('active');
        });
        
        // Try multiple methods to find the current step content
        let currentStepContent = document.querySelector(`.step-content[data-step="${currentStep}"]`);
        
        // Fallback 1: Try by index
        if (!currentStepContent && stepContents[currentStep - 1]) {
            console.log('Fallback 1: Using stepContents[' + (currentStep - 1) + ']');
            currentStepContent = stepContents[currentStep - 1];
        }
        
        // Fallback 2: Try querying all and finding by data-step
        if (!currentStepContent) {
            console.log('Fallback 2: Searching all step contents...');
            stepContents.forEach((content) => {
                const stepNum = parseInt(content.getAttribute('data-step'));
                if (stepNum === currentStep) {
                    currentStepContent = content;
                }
            });
        }
        
        console.log('Looking for step:', currentStep);
        console.log('Found step content:', currentStepContent ? 'YES' : 'NO');
        
        if (currentStepContent) {
            currentStepContent.classList.add('active');
            // Force display with inline style as backup
            currentStepContent.style.display = 'flex';
            console.log('✓ Step', currentStep, 'is now active');
        } else {
            console.error('✗ ERROR: Step', currentStep, 'content not found!');
            console.error('Available steps:', Array.from(stepContents).map(c => c.getAttribute('data-step')));
        }

        stepItems.forEach((item) => {
            const stepNumber = parseInt(item.dataset.step);
            item.classList.remove('active', 'completed');
            if (stepNumber === currentStep) item.classList.add('active');
            else if (stepNumber < currentStep) item.classList.add('completed');
        });
        
        console.log('Step display updated');
    }

    // Update button visibility
    function updateButtonVisibility() {
        console.log('=== UPDATING BUTTON VISIBILITY ===');
        console.log('Current step:', currentStep);
        
        // Step 1: Only Next button
        // Steps 2-3: Back and Next buttons
        // Step 4: Back and Register buttons
        
        // Previous/Back button
        if (prevBtn) {
            if (currentStep === 1) {
                // Step 1: Hide Back button
                prevBtn.classList.add('hidden');
                prevBtn.style.cssText = 'display: none !important; visibility: hidden !important; opacity: 0 !important; pointer-events: none !important; width: 0 !important; height: 0 !important; padding: 0 !important; margin: 0 !important;';
                console.log('✓ Back button HIDDEN (step 1)');
            } else {
                // Steps 2-4: Show Back button
                prevBtn.classList.remove('hidden');
                prevBtn.style.cssText = 'display: block !important; visibility: visible !important; opacity: 1 !important; pointer-events: auto !important;';
                console.log('✓ Back button SHOWN (step ' + currentStep + ')');
            }
        }
        
        // Next button
        if (nextBtn) {
            if (currentStep === totalSteps) {
                // Step 4: Hide Next button
                nextBtn.classList.add('hidden');
                nextBtn.style.cssText = 'display: none !important; visibility: hidden !important; opacity: 0 !important; pointer-events: none !important; width: 0 !important; height: 0 !important; padding: 0 !important; margin: 0 !important;';
                console.log('✓ Next button HIDDEN (step 4)');
            } else {
                // Steps 1-3: Show Next button
                nextBtn.classList.remove('hidden');
                nextBtn.style.cssText = 'display: block !important; visibility: visible !important; opacity: 1 !important; pointer-events: auto !important;';
                nextBtn.textContent = 'Next';
                console.log('✓ Next button SHOWN (step ' + currentStep + ')');
            }
        }
        
        // Register button
        if (submitBtn) {
            if (currentStep === totalSteps) {
                // Step 4: Show Register button
                submitBtn.classList.remove('hidden');
                submitBtn.style.cssText = 'display: block !important; visibility: visible !important; opacity: 1 !important; pointer-events: auto !important;';
                console.log('✓ Register button SHOWN (step 4)');
            } else {
                // Steps 1-3: Hide Register button
                submitBtn.classList.add('hidden');
                submitBtn.style.cssText = 'display: none !important; visibility: hidden !important; opacity: 0 !important; pointer-events: none !important; width: 0 !important; height: 0 !important; padding: 0 !important; margin: 0 !important;';
                console.log('✓ Register button HIDDEN (step ' + currentStep + ')');
            }
        }
        
        console.log('Button visibility updated');
    }

    // Universal rule: numbers followed by letters not allowed (but fields can be exempted)
    function hasNumbersFollowedByLetters(value) {
        return /[0-9]+[a-zA-Z]+/.test(value);
    }

    // Helper that decides whether a field should be checked by the "numbers followed by letters" rule
    function shouldApplyNumberLetterRule(field) {
        if (!field) return false;
        // skip this rule for:
        // - emails
        // - password inputs
        // - ID field (because it has its own format: xxxx-xxxx)
        // - security answer inputs (ids starting with secA)
        const id = (field.id || '').toLowerCase();
        const type = (field.type || '').toLowerCase();
        if (type === 'email' || type === 'password') return false;
        if (id === 'idnum') return false;
        if (/^seca\d*/.test(id)) return false; // secA1, secA2, secA3
        return true;
    }

    // Validate current step
    function validateCurrentStep() {
        const currentStepContent = document.querySelector(`.step-content[data-step="${currentStep}"]`);
        if (!currentStepContent) {
            console.error('Step content not found for step', currentStep);
            return true; // Allow progression if step not found
        }
        
        const requiredFields = currentStepContent.querySelectorAll('input[required], select[required]');
        let isValid = true;
        let hasErrors = false;

        clearErrorStyles();

        // Check all fields for blank and number-letter pattern (but skip fields where it makes no sense)
        requiredFields.forEach(field => {
            // Skip readonly fields that are auto-filled (like Age and ID)
            if (field.readOnly && (field.id === 'Age' || field.id === 'idnum')) {
                // Just check if it has a value, don't apply other validations
                if (!field.value || field.value.trim() === '') {
                    showFieldError(field, 'This field is required');
                    isValid = false;
                }
                return; // Skip further validation for auto-filled readonly fields
            }
            
            // Skip hidden fields
            if (field.style.display === 'none' || field.offsetParent === null) {
                return;
            }
            
            const value = (field.value || '').trim();

            if (!value) {
                showFieldError(field, 'This field is required');
                isValid = false;
            } else if (shouldApplyNumberLetterRule(field) && hasNumbersFollowedByLetters(value)) {
                showFieldError(field, 'Numbers followed directly by letters are not allowed');
                isValid = false;
            }
        });

        // Step 1: Personal Info
        if (currentStep === 1) {
            const fname = document.getElementById('Fname');
            const mname = document.getElementById('Mname');
            const lname = document.getElementById('Lname');
            const idnum = document.getElementById('idnum');
            const age = document.getElementById('Age');

            function getNameErrorMsg(fieldName, value) {
                if (/\d/.test(value)) return `${fieldName} must not contain numbers`;
                if (/[^a-zA-Z\-\s]/.test(value)) return `${fieldName} can only contain letters, spaces, and hyphen (-)`;
                if (/\s{2,}/.test(value)) return `${fieldName} must not contain multiple consecutive spaces`;
                if (/([a-zA-Z])\1{2,}/.test(value)) return `${fieldName} must not contain multiple consecutive identical letters.`;
                return '';
            }

            [fname, mname, lname].forEach(field => {
                if (field && field.value) {
                    // if label is present, use it; otherwise use id/name fallback
                    const labelText = (field.labels && field.labels[0]) ? field.labels[0].innerText : (field.placeholder || field.name || field.id);
                    const msg = getNameErrorMsg(labelText, field.value);
                    if (msg) {
                        showFieldError(field, msg);
                        isValid = false;
                    }
                }
            });

            if (idnum && idnum.value && !isValidIdNumber(idnum.value)) {
                showFieldError(idnum, 'ID number must be in format YYYY-#### (e.g., 2025-0001)');
                isValid = false;
            }
            if (idnum && idnum.getAttribute('data-exists') === 'true') {
                showFieldError(idnum, 'This ID number is already registered!');
                isValid = false;
            }

            // Validate age if birthday is filled
            const birthday = document.getElementById('Birthday');
            if (birthday && birthday.value) {
                if (!age || !age.value || age.value.trim() === '') {
                    // Try to calculate age if not set
                    const bday = new Date(birthday.value);
                    const today = new Date();
                    let calculatedAge = today.getFullYear() - bday.getFullYear();
                    const monthDiff = today.getMonth() - bday.getMonth();
                    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < bday.getDate())) {
                        calculatedAge--;
                    }
                    if (calculatedAge >= 0) {
                        age.value = calculatedAge;
                    }
                }
                
                if (age && age.value) {
                    const ageNum = parseInt(age.value);
                    if (isNaN(ageNum) || ageNum < 18) {
                        showFieldError(age, 'You must be at least 18 years old');
                        isValid = false;
                    }
                }
            } else if (age && !age.value) {
                // Age is required but birthday not set
                showFieldError(age, 'Please select your birthday first');
                isValid = false;
            }
        }

        // Step 2: Contact & Address
        else if (currentStep === 2) {
            const email = document.getElementById('mail');
            const mobile = document.getElementById('mobile');
            const zipCode = document.getElementById('ZipCode');
            const streetname = document.getElementById('Street');
            const barangayname = document.getElementById('Barangay');
            const cityname = document.getElementById('City');
            const provincename = document.getElementById('Province');
            const countryname = document.getElementById('Country');

            if (email && email.value && !isValidEmail(email.value)) {
                showFieldError(email, 'Please enter a valid email address');
                isValid = false;
            }
            if (email && email.getAttribute('data-exists') === 'true') {
                showFieldError(email, 'This email is already registered!');
                isValid = false;
            }

            if (mobile && mobile.value) {
                if (!mobile.value.startsWith('09')) {
                    showFieldError(mobile, 'Mobile number must start with "09"');
                    isValid = false;
                } else if (/[^0-9]/.test(mobile.value)) {
                    showFieldError(mobile, 'Mobile number must contain only digits');
                    isValid = false;
                } else if (mobile.value.length !== 11) {
                    showFieldError(mobile, 'Mobile number must be exactly 11 digits');
                    isValid = false;
                }
            }

            function getAddressErrorMsg(fieldName, value) {
                if (/([a-zA-Z])\1{2,}/.test(value)) return `${fieldName} must not contain multiple consecutive identical letters.`;
                if (/[^A-Za-z0-9\-\s]/.test(value)) return `${fieldName} can only contain letters, numbers, spaces, and hyphen (-)`;
                return '';
            }

            [streetname, barangayname, cityname, provincename, countryname].forEach((field) => {
                if (field && field.value) {
                    const labelText = (field.labels && field.labels[0]) ? field.labels[0].innerText : (field.placeholder || field.name || field.id);
                    const msg = getAddressErrorMsg(labelText, field.value);
                    if (msg) {
                        showFieldError(field, msg);
                        isValid = false;
                    }
                }
            });

            if (zipCode && zipCode.value) {
                if (/[a-zA-Z]/.test(zipCode.value)) {
                    showFieldError(zipCode, 'Zip code must not contain letters');
                    isValid = false;
                } else if (/[^0-9]/.test(zipCode.value)) {
                    showFieldError(zipCode, 'Zip code must not contain special characters');
                    isValid = false;
                } else if (!/^[0-9]{4,5}$/.test(zipCode.value)) {
                    showFieldError(zipCode, 'Zip code must be 4 or 5 digits');
                    isValid = false;
                }
            }
        }

        // Step 3: Account Security
        else if (currentStep === 3) {
            const username = document.getElementById('username');
            const password = document.getElementById('password');
            const confirmPassword = document.getElementById('confirmPassword');

            if (username && username.value) {
                const value = username.value;
                if (value.length < 3) {
                    showFieldError(username, 'Username must be at least 3 characters long');
                    isValid = false;
                } else if (value.length > 20) {
                    showFieldError(username, 'Username must not exceed 20 characters');
                    isValid = false;
                } else if (!/^[a-zA-Z0-9_-]+$/.test(value)) {
                    showFieldError(username, 'Username does not allow special characters or spaces');
                    isValid = false;
                } else if (/([A-Za-z0-9])\1{2,}/.test(value)) {
                    showFieldError(username, 'Username cannot have three identical consecutive characters');
                    isValid = false;
                }
            }

            if (password && password.value && !isValidPassword(password.value)) {
                showFieldError(password, 'Password must be at least 8 characters with uppercase, lowercase, number and special character');
                isValid = false;
            }
            if (confirmPassword && password && confirmPassword.value !== password.value) {
                showFieldError(confirmPassword, 'Passwords do not match');
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
                
                if (!questionSelect.value) {
                    showFieldError(questionSelect, 'Please select a security question');
                    isValid = false;
                } else if (selectedQuestions.includes(questionSelect.value)) {
                    showFieldError(questionSelect, 'Please select unique security questions');
                    isValid = false;
                } else {
                    selectedQuestions.push(questionSelect.value);
                }

                if (!answerInput.value.trim()) {
                    showFieldError(answerInput, 'Please provide an answer');
                    isValid = false;
                } else if (answerInput.value.trim().length < 3) {
                    showFieldError(answerInput, 'Security answers must be at least 3 characters');
                    isValid = false;
                }
            });
        }

        return isValid;
    }

    function isStepCompleted(stepNumber) {
        const stepContent = document.querySelector(`.step-content[data-step="${stepNumber}"]`);
        const requiredFields = stepContent.querySelectorAll('input[required], select[required]');
        for (let field of requiredFields) {
            if (!field.value.trim()) return false;
        }
        return true;
    }

    // Helpers
    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    function isValidIdNumber(idNumber) {
        return /^[0-9]{4}-[0-9]{4}$/.test(idNumber);
    }

    function isValidPassword(password) {
        return /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/.test(password);
    }

    // Error display helpers
    function showFieldError(field, message) {
        field.style.borderColor = '#e74c3c';
        field.style.backgroundColor = '#fdf2f2';
        const existing = field.parentNode.querySelector('.field-error');
        if (existing) existing.remove();
        const errorDiv = document.createElement('div');
        errorDiv.className = 'field-error';
        errorDiv.style.color = '#e74c3c';
        errorDiv.style.fontSize = '0.8rem';
        errorDiv.style.marginTop = '0.25rem';
        errorDiv.textContent = message;
        field.parentNode.appendChild(errorDiv);
    }

    function clearErrorStyles() {
        document.querySelectorAll('input, select').forEach(f => {
            f.style.borderColor = '';
            f.style.backgroundColor = '';
        });
        document.querySelectorAll('.field-error').forEach(e => e.remove());
    }

    function clearFieldError(field) {
        field.style.borderColor = '';
        field.style.backgroundColor = '';
        const err = field.parentNode.querySelector('.field-error');
        if (err) err.remove();
    }

    // Submission guard (updated)
    form.addEventListener('submit', function(e) {
        // only allow submission if we are on the final step
        if (currentStep !== totalSteps) {
            e.preventDefault();
            return false;
        }

        // run the step validator for the last step
        const stepOk = validateCurrentStep();

        // run global validator from validation.js if available
        const fullOk = (typeof window.validateForm === 'function') ? window.validateForm() : true;

        if (!stepOk || !fullOk) {
            e.preventDefault();
            return false;
        }
        // otherwise allow submit to proceed
    });

    // Auto age calculation
    function setupAgeCalculation() {
        const birthdayField = document.getElementById('Birthday');
        const ageField = document.getElementById('Age');
        
        if (!birthdayField || !ageField) {
            return;
        }
        
        function calculateAgeFromBirthday(birthdate) {
            if (!birthdate) {
                ageField.value = '';
                return;
            }
            
            const bday = new Date(birthdate);
            if (isNaN(bday.getTime())) {
                return; // Invalid date
            }
            
            const today = new Date();
            let age = today.getFullYear() - bday.getFullYear();
            const monthDiff = today.getMonth() - bday.getMonth();
            
            if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < bday.getDate())) {
                age--;
            }
            
            if (age >= 0) {
                ageField.value = age;
            } else {
                ageField.value = '';
            }
        }
        
        // Calculate age on change
        birthdayField.addEventListener('change', function() {
            calculateAgeFromBirthday(this.value);
        });
        
        // Calculate age on input (for immediate feedback)
        birthdayField.addEventListener('input', function() {
            calculateAgeFromBirthday(this.value);
        });
        
        // Also calculate if birthday already has a value (e.g., if user goes back and forth)
        if (birthdayField.value) {
            calculateAgeFromBirthday(birthdayField.value);
        }
    }
    
    // Setup age calculation
    setupAgeCalculation();

    // Real-time checks
    const usernameField = document.getElementById('username');
    if (usernameField) {
        usernameField.addEventListener('blur', async function() {
            if (this.value.trim()) {
                try {
                    const res = await fetch(`check_username.php?username=${encodeURIComponent(this.value)}`);
                    const data = await res.json();
                    if (data.exists) {
                        showFieldError(this, 'This username is already taken!');
                        this.setAttribute('data-exists', 'true');
                    } else {
                        clearFieldError(this);
                        this.removeAttribute('data-exists');
                    }
                } catch (err) {
                    console.error('Error checking username:', err);
                }
            }
        });
    }

    // Function to generate ID
    async function generateId() {
        const idField = document.getElementById('idnum');
        if (!idField) {
            console.error('ID field not found');
            return;
        }
        
        idField.disabled = true;
        idField.value = '';
        idField.style.opacity = '0.6';
        
        try {
            const res = await fetch('generate_id.php');
            
            if (!res.ok) {
                throw new Error(`HTTP error! status: ${res.status}`);
            }
            
            const data = await res.json();
            
            if (data.error) {
                idField.value = '';
                idField.style.borderColor = '#e74c3c';
                idField.style.opacity = '1';
                console.error('Error generating ID:', data.error);
                alert('Error generating ID number. Please refresh the page and try again.');
            } else if (data.idnum) {
                idField.value = data.idnum;
                idField.style.borderColor = '';
                idField.style.opacity = '1';
                clearFieldError(idField);
                idField.removeAttribute('data-exists');
            } else {
                throw new Error('Invalid response from server');
            }
        } catch (err) {
            console.error('Error generating ID:', err);
            idField.value = '';
            idField.style.borderColor = '#e74c3c';
            idField.style.opacity = '1';
            alert('Error generating ID number. Please refresh the page and try again.');
        } finally {
            idField.disabled = false;
        }
    }
    
    // Auto-generate ID number immediately on page load
    generateId();
    
    // Check ID number uniqueness on blur
    const idField = document.getElementById('idnum');
    if (idField) {
        idField.addEventListener('blur', async function() {
            if (this.value.trim()) {
                try {
                    const res = await fetch(`check_username.php?idnum=${encodeURIComponent(this.value)}`);
                    const data = await res.json();
                    if (data.exists) {
                        showFieldError(this, 'This ID number is already registered!');
                        this.setAttribute('data-exists', 'true');
                    } else {
                        clearFieldError(this);
                        this.removeAttribute('data-exists');
                    }
                } catch (err) {
                    console.error('Error checking ID number:', err);
                }
            }
        });
    }

    const emailField = document.getElementById('mail');
    if (emailField) {
        emailField.addEventListener('blur', async function() {
            if (this.value.trim()) {
                try {
                    const res = await fetch(`check_email.php?mail=${encodeURIComponent(this.value)}`);
                    const data = await res.json();
                    if (data.exists) {
                        showFieldError(this, 'This email is already registered!');
                        this.setAttribute('data-exists', 'true');
                    } else {
                        clearFieldError(this);
                        this.removeAttribute('data-exists');
                    }
                } catch (err) {
                    console.error('Error checking email:', err);
                }
            }
        });
    }
});
