if (!localStorage.getItem("user") && !window.location.href.includes("login.html")) {
    window.location.href = "login.html";
}


let inventory = [];


async function fetchProductsFromDatabase() {
    try {
        const response = await fetch('api.php');
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        inventory = await response.json();
        
        renderProducts(inventory);
        
    } catch (error) {
        console.error("Failed to fetch inventory:", error);
        if (document.getElementById('productGrid')) {
            document.getElementById('productGrid').innerHTML = '<p style="color:red; grid-column: 1 / -1; text-align: center;">Error loading catalog. Is your XAMPP Apache/MySQL server running?</p>';
        }
    }
}

const grid = document.getElementById('productGrid');
const shapeFilter = document.getElementById('shapeFilter');
const materialFilter = document.getElementById('materialFilter');
const priceFilter = document.getElementById('priceFilter');
const priceValueDisplay = document.getElementById('priceValue');

let cart = JSON.parse(localStorage.getItem("cart")) || [];

function addToCart(product) {
    cart.push(product);
    localStorage.setItem("cart", JSON.stringify(cart));
    updateCartUI();

    console.log(`${product.name} added to cart`);
    alert(`${product.name} added to cart!`); 
}

function updateCartUI() {
    const cartCount = document.getElementById("cartCount");
    if (cartCount) cartCount.textContent = cart.length;
}

function renderProducts(productsToRender) {
    if (!grid) return;

    grid.innerHTML = '';

    if (productsToRender.length === 0) {
        grid.innerHTML = '<p style="opacity:0.6; grid-column: 1 / -1; text-align: center;">No frames match your criteria.</p>';
        return;
    }

    productsToRender.forEach((product, index) => {
        const card = document.createElement('div');
        card.className = 'card';

        card.innerHTML = `
            <div class="card-img">
                <img src="${product.img}" alt="${product.name}" style="max-width: 100%; height: auto; border-radius: 4px;" />
            </div>

            <h3 class="card-title">${product.name}</h3>
            <p class="card-tags">${product.shape} • ${product.material}</p>
            <p class="card-price">$${product.price}</p>

            <button class="view-btn">Quick View</button>
            <button class="cart-btn">Add to Cart</button>
        `;

        card.style.opacity = 0;
        card.style.transform = "translateY(20px)";

        setTimeout(() => {
            card.style.transition = "all 0.5s ease";
            card.style.opacity = 1;
            card.style.transform = "translateY(0)";
        }, index * 100);

        card.addEventListener('mousemove', (e) => {
            const rect = card.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;

            const rotateX = -(y - rect.height / 2) / 12;
            const rotateY = (x - rect.width / 2) / 12;

            card.style.transform = `rotateX(${rotateX}deg) rotateY(${rotateY}deg) scale(1.05)`;
        });

        card.addEventListener('mouseleave', () => {
            card.style.transform = `rotateX(0) rotateY(0) scale(1)`;
        });

        card.querySelector('.view-btn').onclick = () => showQuickView(product);
        card.querySelector('.cart-btn').onclick = () => addToCart(product);

        grid.appendChild(card);
    });
}

function filterInventory() {
    if (!shapeFilter || !materialFilter || !priceFilter) return;

    const selectedShape = shapeFilter.value;
    const selectedMaterial = materialFilter.value;
    const maxPrice = parseInt(priceFilter.value);

    if (priceValueDisplay) priceValueDisplay.textContent = maxPrice;

    const filtered = inventory.filter(product =>
        (selectedShape === 'all' || product.shape === selectedShape) &&
        (selectedMaterial === 'all' || product.material === selectedMaterial) &&
        product.price <= maxPrice
    );

    renderProducts(filtered);
}

function showQuickView(product) {
    const modal = document.createElement('div');
    modal.className = 'modal';

    modal.innerHTML = `
        <div class="modal-content" style="background: white; padding: 2rem; border-radius: 8px; max-width: 400px; margin: 10% auto; text-align: center; position: relative;">
            <span class="close-btn" style="position: absolute; top: 10px; right: 20px; font-size: 1.5rem; cursor: pointer;">&times;</span>
            <img src="${product.img}" style="max-width: 100%; border-radius: 4px;" />
            <h2>${product.name}</h2>
            <p>${product.shape} • ${product.material}</p>
            <p><strong>$${product.price}</strong></p>
        </div>
    `;

    modal.style.position = 'fixed';
    modal.style.top = '0';
    modal.style.left = '0';
    modal.style.width = '100%';
    modal.style.height = '100%';
    modal.style.backgroundColor = 'rgba(0,0,0,0.5)';
    modal.style.zIndex = '1000';

    document.body.appendChild(modal);

    modal.querySelector('.close-btn').onclick = () => modal.remove();
    modal.onclick = (e) => e.target === modal && modal.remove();
}

async function login(event) {
    if(event) event.preventDefault(); 

    const user = document.getElementById("username").value;
    const pass = document.getElementById("password").value;

    if (!user || !pass) {
        alert("Please enter both username and password.");
        return;
    }

    try {
        const response = await fetch('login_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username: user, password: pass })
        });

         const result = await response.json();

            if (result.status === "success") {
                localStorage.setItem("user", "true");
                localStorage.setItem("role", result.role); 
                
                window.location.href = "index.html"; 
            } else {
            alert("Login Failed: " + result.message);
        }
    } catch (error) {
        console.error("Login Error:", error);
        alert("Server error. Please check if XAMPP is running.");
    }
}


async function logout() {
    try {
        await fetch('logout_api.php');
        
        localStorage.removeItem("user");
        
        window.location.href = "login.html";
    } catch (error) {
        console.error("Logout failed:", error);
    }
}

function logout() {
    localStorage.removeItem("user");
    window.location.href = "login.html";
}

function scrollToProducts() {
    const section = document.getElementById("productGrid");
    if (section) section.scrollIntoView({ behavior: "smooth" });
}

const userName = localStorage.getItem("user");
if (userName && document.getElementById("userName")) {
    document.getElementById("userName").textContent = `Hi, ${userName}`;
}

shapeFilter?.addEventListener('change', filterInventory);
materialFilter?.addEventListener('change', filterInventory);
priceFilter?.addEventListener('input', filterInventory);

fetchProductsFromDatabase();
updateCartUI();