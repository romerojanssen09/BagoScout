<!-- Footer -->
<footer class="bg-gray-900 text-white mt-16 md:mt-6">
    <div class="container mx-auto px-4">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8 pt-12">
            <!-- Company Section -->
            <div class="company-section">
                <h3 class="footer-heading">Company</h3>
                <ul class="space-y-2">
                    <li><a href="<?php echo $pathPrefix; ?>about.php" class="footer-link">About us</a></li>
                    <li><a href="#" class="footer-link">Our Services</a></li>
                    <li><a href="#" class="footer-link">Privacy Policy</a></li>
                    <li><a href="#" class="footer-link">Terms of Service</a></li>
                    <li><a href="<?php echo $pathPrefix; ?>contact.php" class="footer-link">Contact</a></li>
                </ul>
            </div>

            <!-- Get Help Section -->
            <div class="help-section">
                <h3 class="footer-heading">Get Help</h3>
                <ul class="space-y-2">
                    <li><a href="#" class="footer-link">FAQ</a></li>
                    <li><a href="#" class="footer-link">Applying</a></li>
                    <li><a href="#" class="footer-link">Posting</a></li>
                    <li><a href="#" class="footer-link">Recruitment</a></li>
                    <li><a href="#" class="footer-link">Employment</a></li>
                </ul>
            </div>

            <!-- Online Job Portal Section -->
            <div class="portal-section">
                <h3 class="footer-heading">Online Job Portal</h3>
                <ul class="space-y-2">
                    <li><a href="#" class="footer-link">Freelancing</a></li>
                    <li><a href="#" class="footer-link">Hiring</a></li>
                    <li><a href="#" class="footer-link">Local Hiring</a></li>
                    <li><a href="#" class="footer-link">Part-time</a></li>
                    <li><a href="#" class="footer-link">Full-time</a></li>
                </ul>

                <h3 class="footer-heading mt-6">Follow Us</h3>
                <div class="social-icons">
                    <a href="#" class="social-icon"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" class="social-icon"><i class="fab fa-twitter"></i></a>
                    <a href="#" class="social-icon"><i class="fab fa-instagram"></i></a>
                    <a href="#" class="social-icon"><i class="fab fa-linkedin-in"></i></a>
                </div>
            </div>
        </div>

        <div class="border-t border-gray-800 mt-8 pt-8 text-center md:text-left pb-8">
            <p class="text-gray-500">&copy; <?php echo date('Y'); ?> BagoScout. All rights reserved.</p>
        </div>
    </div>
</footer>

<!-- Login Modal -->
<div id="loginModal" class="modal">
    <div class="modal-overlay"></div>
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="text-xl font-semibold text-gray-800">Login to BagoScout</h3>
            <button id="closeModal" class="text-gray-500 hover:text-gray-700">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <form id="loginForm" action="<?php echo $pathPrefix; ?>auth/login-process.php" method="post">
                <div class="form-group">
                    <label for="email" class="form-label">Email Address</label>
                    <input type="email" id="email" name="email" class="form-input" required>
                </div>
                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <div class="relative">
                        <input type="password" id="password" name="password" class="form-input" required>
                        <button type="button" class="toggle-password absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-500">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center">
                        <input type="checkbox" id="remember" name="remember" class="mr-2">
                        <label for="remember" class="text-sm text-gray-600">Remember me</label>
                    </div>
                    <a href="<?php echo $pathPrefix; ?>pages/auth/forgot-password.php" class="text-sm text-blue-500 hover:text-blue-700">Forgot password?</a>
                </div>
                <button type="submit" class="w-full px-4 py-2 bg-blue-800 text-white font-medium rounded-md hover:bg-blue-900 transition duration-300">Login</button>
            </form>
        </div>
    </div>
</div>

<script>
    // Modal functionality
    document.addEventListener('DOMContentLoaded', function() {
        const loginBtn = document.getElementById('loginBtn');
        const loginModal = document.getElementById('loginModal');
        const closeModal = document.getElementById('closeModal');
        const modalOverlay = loginModal ? loginModal.querySelector('.modal-overlay') : null;
        
        // Open modal
        if (loginBtn && loginModal) {
            loginBtn.addEventListener('click', function() {
                loginModal.classList.add('active');
            });
        }
        
        // Close modal
        if (closeModal && loginModal) {
            closeModal.addEventListener('click', function() {
                loginModal.classList.remove('active');
            });
        }
        
        // Close modal when clicking overlay
        if (modalOverlay && loginModal) {
            modalOverlay.addEventListener('click', function() {
                loginModal.classList.remove('active');
            });
        }
        
        // Toggle password visibility
        const togglePassword = document.querySelector('.toggle-password');
        if (togglePassword) {
            togglePassword.addEventListener('click', function() {
                const passwordInput = document.getElementById('password');
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                
                // Toggle icon
                const icon = this.querySelector('i');
                icon.classList.toggle('fa-eye');
                icon.classList.toggle('fa-eye-slash');
            });
        }
    });
</script>
<?php if (isset($additionalScripts)) echo $additionalScripts; ?>