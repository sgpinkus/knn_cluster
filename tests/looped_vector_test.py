import numpy as np
import matplotlib.pyplot as plt


data = np.random.rand(10, 2).astype(np.float32)
x = data[3]
diff = data - x
adiff = np.abs(diff)
adiff1 = 1 - adiff
w = np.stack((adiff, adiff1), axis=1)
wt = np.argmin(w, axis=1, keepdims=True)
wts = -2 * (np.argmin(w, axis=1) - 0.5)
d = np.take_along_axis(w, wt, axis=1).reshape(data.shape) * wts * np.sign(diff)

plt.scatter(data[:, 0], data[:, 1])
xx = np.tile(x, 10).reshape(10,2)
plt.quiver(xx[:,0], xx[:,1], d[:,0], d[:,1],
           scale=1,
           scale_units='xy',
           angles='xy',
           width=0.005,
)
plt.xlim((-0.1,1.1))
plt.ylim((-0.1,1.1))
plt.grid(True, linestyle='--', linewidth=0.5)
plt.show()