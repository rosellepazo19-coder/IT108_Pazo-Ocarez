<?php
require_once 'includes/db_connect.php';

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    // Collect form data
    $idnum = trim($_POST['idnum'] ?? '');
    $Fname = trim($_POST['Fname'] ?? '');
    $Mname = trim($_POST['Mname'] ?? '');
    $Lname = trim($_POST['Lname'] ?? '');
    $Suffix = trim($_POST['Suffix'] ?? '');
    $mail = trim($_POST['mail'] ?? '');
    $sex = $_POST['sex'] ?? '';
    $Birthday = $_POST['Birthday'] ?? '';
    $Age = (int)($_POST['Age'] ?? 0);
    $mobile = trim($_POST['mobile'] ?? '');
    $Street = trim($_POST['Street'] ?? '');
    $Barangay = trim($_POST['Barangay'] ?? '');
    $City = trim($_POST['City'] ?? '');
    $Province = trim($_POST['Province'] ?? '');
    $Country = trim($_POST['Country'] ?? '');
    $ZipCode = trim($_POST['ZipCode'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirmPassword'] ?? '';
    $secQ1 = $_POST['secQ1'] ?? '';
    $secA1 = trim($_POST['secA1'] ?? '');
    $secQ2 = $_POST['secQ2'] ?? '';
    $secA2 = trim($_POST['secA2'] ?? '');
    $secQ3 = $_POST['secQ3'] ?? '';
    $secA3 = trim($_POST['secA3'] ?? '');
    $role = $_POST['role'] ?? 'borrower';

    // Basic validation
    if (empty($idnum)) $errors[] = "ID number is required.";
    if (empty($Fname)) $errors[] = "First name is required.";
    if (empty($Lname)) $errors[] = "Last name is required.";
    if (empty($mail)) $errors[] = "Email is required.";
    if (empty($password)) $errors[] = "Password is required.";
    if ($password !== $confirmPassword) $errors[] = "Passwords do not match.";

    if (empty($errors)) {
        // Check if email exists
        $stmt = $mysqli->prepare("SELECT 1 FROM users WHERE mail = ? LIMIT 1");
        $stmt->bind_param("s", $mail);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $errors[] = "Email already exists!";
        }
        $stmt->close();

        // Check if ID exists
        if (empty($errors)) {
            $stmt = $mysqli->prepare("SELECT 1 FROM users WHERE idnum = ? LIMIT 1");
            $stmt->bind_param("s", $idnum);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $errors[] = "ID number already exists!";
            }
            $stmt->close();
        }

        // Insert user
        if (empty($errors)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $mysqli->prepare("INSERT INTO users (
                idnum, Fname, Mname, Lname, Suffix, mail, sex, Birthday, Age,
                mobile, Street, Barangay, City, Province, Country, ZipCode,
                password, role, secQ1, secA1, secQ2, secA2, secQ3, secA3
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $types = 'ssssssssi' . str_repeat('s', 15);
            $stmt->bind_param(
                $types,
                $idnum, $Fname, $Mname, $Lname, $Suffix, $mail, $sex, $Birthday, $Age,
                $mobile, $Street, $Barangay, $City, $Province, $Country, $ZipCode,
                $hashed_password, $role, $secQ1, $secA1, $secQ2, $secA2, $secQ3, $secA3
            );

            if ($stmt->execute()) {
                // Registration successful - redirect to login
                header('Location: login.php?registered=1');
                exit();
            } else {
                $errors[] = "Registration failed: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration - CBR Agricultural System</title>
    <link rel="stylesheet" href="styles/style.css">
    <link rel="stylesheet" href="styles/public-shared.css">
    <link rel="stylesheet" href="styles/registration.css">
    <style>
        body {
            position: relative;
            min-height: 100vh;
        }
        
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url('uploads/BACKGORUNDDD.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            filter: blur(5px);
            z-index: -1;
        }
    </style>
</head>
<body>
    <header class="public-header">
        <div class="header-content">
            <div class="header-left">
                <div class="header-icon">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M3 21V8L12 3L21 8V21H3Z" fill="#FFD700" stroke="#FFD700" stroke-width="1.5" stroke-linejoin="round"/>
                        <path d="M3 8L12 3L21 8" stroke="#FFD700" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M12 3V21" stroke="#FFD700" stroke-width="1.5"/>
                        <rect x="6" y="12" width="4" height="6" fill="#1b5e20"/>
                        <rect x="14" y="12" width="4" height="6" fill="#1b5e20"/>
                        <circle cx="9" cy="15" r="0.5" fill="#FFD700"/>
                        <circle cx="16" cy="15" r="0.5" fill="#FFD700"/>
                    </svg>
                </div>
                <h1>CABADBARAN AGRICULTURAL SUPPLY AND EQUIPMENT LENDING SYSTEM</h1>
            </div>
            <nav class="header-nav">
                <a href="home.php" class="nav-link">Home</a>
                <a href="service.php" class="nav-link">Service</a>
                <a href="about.php" class="nav-link">About</a>
                <a href="contact.php" class="nav-link">Contact</a>
                <a href="login.php" class="nav-link primary">Log In</a>
            </nav>
        </div>
    </header>
    <div class="registration-container">
        <div class="registration-form">

            <?php if (!empty($errors)): ?>
                <div class="alert error">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert success">
                    <?php echo htmlspecialchars($success); ?>
                    <br><a href="login.php">Click here to login</a>
                </div>
            <?php else: ?>

            <form id="registrationForm" method="POST" action="register.php">
                <!-- Step Progress Indicator -->
                <div class="step-progress">
                    <div class="step-item active" data-step="1">
                        <div class="step-number">1</div>
                        <div class="step-label">Personal Info</div>
                    </div>
                    <div class="step-item" data-step="2">
                        <div class="step-number">2</div>
                        <div class="step-label">Contact Info</div>
                    </div>
                    <div class="step-item" data-step="3">
                        <div class="step-number">3</div>
                        <div class="step-label">Account Security</div>
                    </div>
                    <div class="step-item" data-step="4">
                        <div class="step-number">4</div>
                        <div class="step-label">Security Questions</div>
                    </div>
                </div>

                <!-- Step 1: Personal Information -->
                <div class="step active" data-step="1">
                    <h2>Personal Information</h2>
                    <div class="form-grid">
                        <div class="form-field">
                            <label for="idnum">ID Number<span class="required-asterisk">*</span></label>
                            <input type="text" id="idnum" name="idnum" required readonly style="background-color: #f5f5f5;">
                        </div>
                        <div class="form-field">
                            <label for="Fname">First Name<span class="required-asterisk">*</span></label>
                            <input type="text" id="Fname" name="Fname" placeholder="First Name" required>
                        </div>
                        <div class="form-field">
                            <label for="Mname">Middle Name<span class="optional-text"> (optional)</span></label>
                            <input type="text" id="Mname" name="Mname" placeholder="Middle Name">
                        </div>
                        <div class="form-field">
                            <label for="Lname">Last Name<span class="required-asterisk">*</span></label>
                            <input type="text" id="Lname" name="Lname" placeholder="Last Name" required>
                        </div>
                        <div class="form-field">
                            <label for="Suffix">Suffix<span class="optional-text"> (optional)</span></label>
                            <select id="Suffix" name="Suffix">
                                <option value="">-- Select Suffix --</option>
                                <option value="Jr.">Jr.</option>
                                <option value="Sr.">Sr.</option>
                                <option value="II">II</option>
                                <option value="III">III</option>
                                <option value="IV">IV</option>
                            </select>
                        </div>
                        <div class="form-field">
                            <label for="sex">Sex<span class="required-asterisk">*</span></label>
                            <select id="sex" name="sex" required>
                                <option value="">Select Gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                        <div class="form-field">
                            <label for="Birthday">Birthday<span class="required-asterisk">*</span></label>
                            <input type="date" id="Birthday" name="Birthday" required>
                        </div>
                        <div class="form-field">
                            <label for="Age">Age<span class="required-asterisk">*</span></label>
                            <input type="number" id="Age" name="Age" required readonly style="background-color: #f5f5f5;">
                        </div>
                        <div class="form-field">
                            <label for="role">Role<span class="required-asterisk">*</span></label>
                            <input type="text" id="role" name="role" value="borrower" readonly required style="background-color: #f5f5f5;">
                        </div>
                    </div>
                </div>

                <!-- Step 2: Contact Information -->
                <div class="step" data-step="2">
                    <h2>Contact Information</h2>
                    <div class="form-grid">
                        <div class="form-field">
                            <label for="mail">Email<span class="required-asterisk">*</span></label>
                            <input type="email" id="mail" name="mail" placeholder="Email" required>
                        </div>
                        <div class="form-field">
                            <label for="mobile">Mobile Number<span class="required-asterisk">*</span></label>
                            <input type="tel" id="mobile" name="mobile" placeholder="Mobile Number (11 digits)" required pattern="[0-9]{11}" maxlength="11">
                        </div>
                        <div class="form-field">
                            <label for="Street">Street<span class="required-asterisk">*</span></label>
                            <input type="text" id="Street" name="Street" placeholder="Street" required>
                        </div>
                        <div class="form-field">
                            <label for="Barangay">Barangay<span class="required-asterisk">*</span></label>
                            <input type="text" id="Barangay" name="Barangay" placeholder="Barangay" required>
                        </div>
                        <div class="form-field">
                            <label for="City">City<span class="required-asterisk">*</span></label>
                            <input type="text" id="City" name="City" placeholder="City/Municipality" required>
                        </div>
                        <div class="form-field">
                            <label for="Province">Province<span class="required-asterisk">*</span></label>
                            <input type="text" id="Province" name="Province" placeholder="Province" required>
                        </div>
                        <div class="form-field">
                            <label for="Country">Country<span class="required-asterisk">*</span></label>
                            <input type="text" id="Country" name="Country" placeholder="Country" required>
                        </div>
                        <div class="form-field">
                            <label for="ZipCode">Zip Code<span class="required-asterisk">*</span></label>
                            <input type="text" id="ZipCode" name="ZipCode" placeholder="Zip Code" required>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Account Security -->
                <div class="step" data-step="3">
                    <h2>Account Security</h2>
                    <div class="form-grid">
                        <div class="form-field">
                            <label for="password">Password<span class="required-asterisk">*</span></label>
                            <div class="password-input-wrapper">
                                <input type="password" id="password" name="password" placeholder="Password" required>
                                <button type="button" class="toggle-password" id="togglePassword" aria-label="Show password">
                                    <svg class="eye-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                        <circle cx="12" cy="12" r="3"></circle>
                                    </svg>
                                </button>
                            </div>
                            <div class="password-requirements" style="display: none;">
                                <p class="password-requirement-title">Password must contain:</p>
                                <ul class="password-requirement-list">
                                    <li id="req-uppercase">At least one uppercase letter (A-Z)</li>
                                    <li id="req-lowercase">At least one lowercase letter (a-z)</li>
                                    <li id="req-number">At least one number (0-9)</li>
                                    <li id="req-special">At least one special character (@$!%*?&)</li>
                                    <li id="req-length">Minimum 8 characters</li>
                                </ul>
                            </div>
                            <div id="password-strength-status"></div>
                        </div>
                        <div class="form-field">
                            <label for="confirmPassword">Confirm Password<span class="required-asterisk">*</span></label>
                            <div class="password-input-wrapper">
                                <input type="password" id="confirmPassword" name="confirmPassword" placeholder="Confirm Password" required>
                                <button type="button" class="toggle-password" id="toggleConfirmPassword" aria-label="Show password">
                                    <svg class="eye-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                        <circle cx="12" cy="12" r="3"></circle>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 4: Security Questions -->
                <div class="step" data-step="4">
                    <h2>Security Questions</h2>
                    <p>Please choose 3 unique security questions and provide your answers.</p>
                    
                    <div class="security-questions-grid">
                        <div class="security-question-item">
                            <label for="secQ1">Security Question 1<span class="required-asterisk">*</span></label>
                            <select id="secQ1" name="secQ1" required>
                                <option value="">-- Select a question --</option>
                                <option value="pet">What is your favorite dessert?</option>
                                <option value="school">What was the name of your school in Highschool?</option>
                                <option value="city">In what city were you born?</option>
                                <option value="nickname">Who is your childhood bestfriend?</option>
                                <option value="food">What is your favorite color?</option>
                            </select>
                            <input type="text" id="secA1" name="secA1" placeholder="Your Answer" required>
                        </div>

                        <div class="security-question-item">
                            <label for="secQ2">Security Question 2<span class="required-asterisk">*</span></label>
                            <select id="secQ2" name="secQ2" required>
                                <option value="">-- Select a question --</option>
                                <option value="pet">What is your favorite dessert?</option>
                                <option value="school">What was the name of your school in Highschool?</option>
                                <option value="city">In what city were you born?</option>
                                <option value="nickname">Who is your childhood bestfriend?</option>
                                <option value="food">What is your favorite color?</option>
                            </select>
                            <input type="text" id="secA2" name="secA2" placeholder="Your Answer" required>
                        </div>

                        <div class="security-question-item">
                            <label for="secQ3">Security Question 3<span class="required-asterisk">*</span></label>
                            <select id="secQ3" name="secQ3" required>
                                <option value="">-- Select a question --</option>
                                <option value="pet">What is your favorite dessert?</option>
                                <option value="school">What was the name of your school in Highschool?</option>
                                <option value="city">In what city were you born?</option>
                                <option value="nickname">Who is your childhood bestfriend?</option>
                                <option value="food">What is your favorite color?</option>
                            </select>
                            <input type="text" id="secA3" name="secA3" placeholder="Your Answer" required>
                        </div>
                    </div>
                </div>

                <!-- Navigation Buttons -->
                <div class="step-navigation">
                    <button type="button" id="prevBtn" class="btn btn-secondary" style="display: none;">Previous</button>
                    <button type="button" id="nextBtn" class="btn btn-primary">Next</button>
                    <button type="submit" id="submitBtn" name="register" class="btn btn-primary" style="display: none;">Register</button>
                </div>
            </form>

            <p class="auto-switch">Already have an account? <a href="login.php" class="log-btn">Log In</a></p>

            <?php endif; ?>
        </div>
    </div>

    <footer class="public-footer">
        <p>&copy; 2025 Cabadbaran Agricultural Supply and Equipment Lending System. All rights reserved.</p>
    </footer>

    <script src="javascript/registration.js"></script>
</body>
</html>
