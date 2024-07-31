import numpy as np


def norm(data, q, n=2):
  return np.linalg.norm(data - q, n, axis=1)


def snorm(data, q, n=2):
  ''' Spherical norm. '''
  diff = data - q
  adiff = np.abs(diff)
  adiff1 = 1 - adiff
  w = np.stack((adiff, adiff1), axis=1)
  wt = np.argmin(w, axis=1, keepdims=True)
  wts = -2 * (np.argmin(w, axis=1) - 0.5)
  v = np.take_along_axis(w, wt, axis=1).reshape(data.shape) * wts * np.sign(diff)
  return  np.linalg.norm(v, n, axis=1)


def snorm2(data, q):
  return snorm(data, q)


def snorm1(data, q):
  return snorm(data, q, 1)


def norm2(data, q):
  return norm(data, q)


def norm1(data, q):
  return norm(data, q, 1)