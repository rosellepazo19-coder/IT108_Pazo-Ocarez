/**
 * Image Lightbox - Reusable modal for viewing enlarged images
 * Usage: Add class "image-clickable" to images with data-image-path and data-image-name attributes
 */

// Initialize lightbox when page loads
document.addEventListener('DOMContentLoaded', function() {
	initializeImageLightbox();
});

function initializeImageLightbox() {
	// Get all clickable images
	const clickableImages = document.querySelectorAll('.image-clickable');
	
	// Add click event to each image
	clickableImages.forEach(img => {
		img.addEventListener('click', function() {
			const imagePath = this.getAttribute('data-image-path');
			const imageName = this.getAttribute('data-image-name');
			openLightbox(imagePath, imageName);
		});
	});
	
	// Close lightbox when clicking outside the image
	const lightbox = document.getElementById('imageLightbox');
	if (lightbox) {
		lightbox.addEventListener('click', function(e) {
			if (e.target === this) {
				closeLightbox();
			}
		});
	}
	
	// Close lightbox with Escape key
	document.addEventListener('keydown', function(e) {
		if (e.key === 'Escape') {
			closeLightbox();
		}
	});
}

// Open lightbox with image
function openLightbox(imagePath, imageName) {
	const lightbox = document.getElementById('imageLightbox');
	const lightboxImage = document.getElementById('lightboxImage');
	const lightboxTitle = document.getElementById('lightboxTitle');
	
	if (!lightbox || !lightboxImage) {
		console.error('Lightbox elements not found in DOM');
		return;
	}
	
	lightboxImage.src = imagePath;
	lightboxImage.alt = imageName;
	if (lightboxTitle) {
		lightboxTitle.textContent = imageName;
	}
	
	lightbox.classList.add('active');
	document.body.style.overflow = 'hidden'; // Prevent background scrolling
}

// Close lightbox
function closeLightbox() {
	const lightbox = document.getElementById('imageLightbox');
	if (lightbox) {
		lightbox.classList.remove('active');
		document.body.style.overflow = 'auto'; // Restore scrolling
	}
}
