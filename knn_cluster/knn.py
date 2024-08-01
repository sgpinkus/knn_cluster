import numpy as np
from abc import ABC, abstractmethod
from collections import OrderedDict
from . import distances
from .distances import norm2

# Faster but requires MRPT and doesn't support custom distance measure (norm2 only).
# import mrpt
# class MRPTKNNSearch():
#   def __init__(self, data):
#     self.index = mrpt.MRPTIndex(data)
#     self.data = data

#   def knn(self, i, k):
#     x = self.index.exact_search(self.data[i], k+1, return_distances=True)
#     return {
#       'i': i,
#       'n': list(x[0]),
#       'd': list(x[1]),
#       'v': np.array([self.data[i] - self.data[x[0][0]] for i in x[0]]).sum(axis=0)
#     }

#   def knns(self, k):
#     return [self.knn(i, k) for i in range(len(self.data))]


class KNNSearch():
  def __init__(self, data, distance_metric=norm2):
    self.data = data
    self.distance_metric = getattr(distances, distance_metric) if isinstance(distance_metric, str) else distance_metric

  def knn(self, i, k) -> dict[str, any]:
    ''' Return KNN for each data point. Distances and k-vector for each point also
    returned even if you don't need nor want them.
    '''
    d = self.distance_metric(self.data, self.data[i])
    n = d.argsort()[:k+1]
    d = d.take(n)
    return {
      'i': i,
      'n': n,
      'd': d,
      'v': np.array([self.data[x] - self.data[i] for x in n]).sum(axis=0)
    }

  def knns(self, k) -> list[dict[str, any]]:
    return [self.knn(i, k) for i in range(len(self.data))]


def knn_cluster(data: np.array, knns: list, k: int, pair_conditions: list) -> list[int]:
  ''' Agglomeratively, create a tree representing clusters as they are merged into
  connected components, then reduce component tree to a partition, then give each
  component a cardinal label starting from 0.
  '''
  def find_root_label(n, label, clustering):
    while clustering[n] != n and clustering[n] != label:
      n = clustering[n]
    return n

  def flatten_cluster_tree(clustering):
    _clustering = clustering.copy()
    for i, n in enumerate(clustering):
      _clustering[i] = find_root_label(n, None, clustering)
    return _clustering

  def relabel_clustering(clustering):
    labels = list(set(clustering))
    labels = OrderedDict(zip(labels, range(len(labels))))
    return len(labels), [labels[i] for i in clustering]

  # A forest of trees representing clusters. clustering[i] = j => j is parent node of i. Root nodes are s.t. clustering[i] = i
  clustering = list(range(len(knns)))

  for cardinal_label, label in enumerate(clustering): # Merge all node['n'] into same cluster by assign all new cluster number
    if label != cardinal_label:
      clustering[label] = cardinal_label
      label = cardinal_label
    for n in knns[label]['n'][:k+1]:
      if len(pair_conditions):
        if not np.array(list(map(lambda c: c(knns[label], knns[n], data), pair_conditions))).all():
          continue
      if clustering[n] != label:
        root_label_index = find_root_label(n, label, clustering)
        clustering[root_label_index] = label
    clustering[label] = label

  clustering = flatten_cluster_tree(clustering)
  n, clustering = relabel_clustering(clustering)

  return n, clustering