
document.addEventListener('DOMContentLoaded', loadCart);

function loadCart() {
    const cart = JSON.parse(localStorage.getItem('visionflow_cart')) || [];
    const cartContainer = document.getElementById('cartItems');
    let total = 0;

    cartContainer.innerHTML = '';

    if (cart.length === 0) {
        cartContainer.innerHTML = '<div class="empty-cart">Your cart is currently empty.</div>';
        document.getElementById('totalPrice').innerText = "0.00";
        return;
    }

    cart.forEach((item, index) => {
        total += parseFloat(item.price);

        cartContainer.innerHTML += `
            <div class="cart-item">
                <img src="${item.img_url}" alt="${item.name}" class="cart-img" onerror="this.src='https://via.placeholder.com/100?text=No+Image'">
                <div class="cart-details">
                    <h3>${item.name}</h3>
                    <button onclick="removeItem(${index})">Remove</button>
                </div>
                <div class="cart-price">$${item.price}</div>
            </div>
        `;
    });

    document.getElementById('totalPrice').innerText = total.toFixed(2);
}

function removeItem(index) {
    let cart = JSON.parse(localStorage.getItem('visionflow_cart')) || [];
    
    cart.splice(index, 1);
    
    localStorage.setItem('visionflow_cart', JSON.stringify(cart));
    
    loadCart();
}

function checkout() {
    const cart = JSON.parse(localStorage.getItem('visionflow_cart')) || [];
    
    if (cart.length === 0) {
        alert("Add some frames to your cart before checking out!");
        return;
    }

    const btn = document.querySelector('.cart-summary button');
    btn.textContent = "Processing...";
    btn.style.background = "#ccc";
    btn.style.color = "#333";
    btn.disabled = true;

    setTimeout(() => {
        localStorage.removeItem('visionflow_cart');
        
        alert("Success! Your order has been placed. Redirecting to catalog...");
        window.location.href = "index.html";
    }, 1500); 
}